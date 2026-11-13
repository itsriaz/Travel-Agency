<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Services\SupplierPaymentVoidImpactService;
use App\Services\SupplierSettlementWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$previewService = new SupplierPaymentVoidImpactService($app);
$voidService = new SupplierSettlementWorkspaceService($app);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};

$payment = $db->query('SELECT id, supplier_id, branch_id, currency FROM supplier_payments
    WHERE payment_no = "SPAY-000264" AND status <> "void"')->fetch(PDO::FETCH_ASSOC);
$assert(is_array($payment), 'A posted cross-branch supplier payment is available for the rollback-only test.');
$actorId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
    WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1')->fetchColumn();
$assert($actorId > 0, 'An administrator is available for the rollback-only confirmation.');

$before = [
    'advances' => (int) $db->query('SELECT COUNT(*) FROM supplier_advances')->fetchColumn(),
    'applications' => (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn(),
    'journals' => (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
];

$db->beginTransaction();
try {
    $insert = $db->prepare('INSERT INTO supplier_advances
        (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no,
         received_at, payment_method, created_by_user_id)
        VALUES (?, ?, ?, 1000000, 1000000, "TEST-ROLLBACK-CROSS-BRANCH", "2026-01-01", "cash", ?)');
    $insert->execute([(int) $payment['supplier_id'], (int) $payment['branch_id'],
        (string) $payment['currency'], $actorId]);
    $advanceId = (int) $db->lastInsertId();
    $insert->execute([(int) $payment['supplier_id'], (int) $payment['branch_id'],
        'AED', $actorId]);
    $otherCurrencyAdvanceId = (int) $db->lastInsertId();

    $preview = $previewService->preview((int) $payment['id'], [1, 2]);
    $crossBranchLines = array_values(array_filter($preview['replacement']['lines'],
        static fn (array $line): bool => (int) $line['advance_id'] === $advanceId
            && (int) $line['branch_id'] !== (int) $payment['branch_id']));
    $assert($preview['replacement']['can_apply'] && $crossBranchLines !== [],
        'Same-supplier, same-currency credit can be proposed across branches without a global source payment.');
    $assert(! in_array($otherCurrencyAdvanceId, array_column($preview['replacement']['lines'], 'advance_id'), true),
        'Different-currency credit is not proposed without an explicit conversion.');
    $assert((int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn()
        === $before['applications'], 'Preview does not apply the proposed credit.');

    try {
        $previewService->preview((int) $payment['id'], [(int) $payment['branch_id']]);
        throw new RuntimeException('A user without the destination branch was allowed to preview the void.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'another branch'),
            'Preview still requires access to the affected branch.');
    }

    $result = $voidService->voidGlobalSupplierPayment([
        'supplier_payment_id' => (int) $payment['id'],
        'void_reason' => 'Rollback-only cross-branch replacement test',
        'confirm_reopen_payables' => '1',
        'impact_token' => $preview['impact_token'],
        'replacement_token' => $preview['replacement']['token'],
        'apply_replacement_advances' => '1',
    ], $actorId, [1, 2]);
    $postedCrossBranch = array_values(array_filter($result['replacement']['applications'],
        static fn (array $row): bool => (int) $row['advance_id'] === $advanceId
            && (int) $row['branch_id'] !== (int) $payment['branch_id']));
    $assert(count($postedCrossBranch) === count($crossBranchLines),
        'Confirmation posts each reviewed cross-branch replacement once.');
    $assert(min(array_column($postedCrossBranch, 'journal_entry_id')) > 0,
        'Cross-branch replacement has journal evidence.');
} finally {
    $db->rollBack();
}

$db->beginTransaction();
try {
    $db->prepare('UPDATE supplier_advances SET available_amount = 0 WHERE supplier_id = ?')
        ->execute([(int) $payment['supplier_id']]);
    $noCreditPreview = $previewService->preview((int) $payment['id'], [1, 2]);
    $assert(! $noCreditPreview['replacement']['can_apply'],
        'The replacement option is absent when this supplier has no available credit.');
} finally {
    $db->rollBack();
}

$assert((int) $db->query('SELECT COUNT(*) FROM supplier_advances')->fetchColumn() === $before['advances']
    && (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn() === $before['applications']
    && (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $before['journals'],
    'Rollback leaves advances, applications, and journals unchanged.');
