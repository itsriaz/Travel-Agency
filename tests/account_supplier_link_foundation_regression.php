<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\CounterpartyLinkRepository;
use App\Services\CounterpartyLinkService;

$app = (isset($app) && $app instanceof App) ? $app : App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$service = new CounterpartyLinkService($app);
$repository = new CounterpartyLinkRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$expectFailure = static function (callable $callback, string $contains) use ($check): bool {
    try {
        $callback();
    } catch (Throwable $exception) {
        return str_contains($exception->getMessage(), $contains);
    }

    return false;
};

echo 'Account holder–supplier link foundation regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$adminUserId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();
$branchId = (int) ($db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$check('Financial administrator and active branch are available', $adminUserId > 0 && $branchId > 0);
if ($adminUserId <= 0 || $branchId <= 0) {
    return 1;
}

$db->beginTransaction();

try {
    $suffix = date('YmdHis') . '-' . random_int(1000, 9999);
    $insertAccount = $db->prepare(
        'INSERT INTO business_sources (code, name, phone, description, is_system, is_active)
         VALUES (:code, :name, :phone, :description, 0, 1)'
    );
    $accountIds = [];
    foreach (['Fida', 'Alternate Account'] as $index => $name) {
        $insertAccount->execute([
            'code' => 'reg_link_account_' . $index . '_' . $suffix,
            'name' => 'REG ' . $name . ' ' . $suffix,
            'phone' => '0300' . random_int(1000000, 9999999),
            'description' => 'Rollback-only account–supplier link fixture.',
        ]);
        $accountIds[] = (int) $db->lastInsertId();
    }

    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active, notes)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1, :notes)'
    );
    $supplierIds = [];
    foreach (['Fida', 'Alternate Supplier'] as $index => $name) {
        $insertSupplier->execute([
            'branch_id' => $branchId,
            'code' => 'REG-LINK-SUP-' . $index . '-' . $suffix,
            'name' => 'REG ' . $name . ' ' . $suffix,
            'notes' => 'Rollback-only account–supplier link fixture.',
        ]);
        $supplierIds[] = (int) $db->lastInsertId();
    }

    $financialCountsBefore = [
        'journal_entries' => (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'customer_receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'customer_receivables' => (int) $db->query('SELECT COUNT(*) FROM customer_receivable_items')->fetchColumn(),
        'supplier_obligations' => (int) $db->query('SELECT COUNT(*) FROM supplier_obligations')->fetchColumn(),
    ];

    $result = $service->linkAccountToSupplier(
        $accountIds[0],
        $supplierIds[0],
        $adminUserId,
        'Regression link confirmation.'
    );
    $link = $repository->findByBusinessSourceId($accountIds[0]);
    $check(
        'Separate account and supplier primary keys are linked explicitly',
        ($result['action'] ?? '') === 'linked'
            && (int) ($link['business_source_id'] ?? 0) === $accountIds[0]
            && (int) ($link['supplier_id'] ?? 0) === $supplierIds[0],
        json_encode($link, JSON_THROW_ON_ERROR)
    );

    $sameResult = $service->linkAccountToSupplier($accountIds[0], $supplierIds[0], $adminUserId);
    $fixtureLinkCountStatement = $db->prepare(
        'SELECT COUNT(*) FROM business_source_supplier_links WHERE business_source_id = :business_source_id'
    );
    $fixtureLinkCountStatement->execute(['business_source_id' => $accountIds[0]]);
    $check(
        'Saving the same link twice is idempotent',
        ($sameResult['action'] ?? '') === 'unchanged'
            && (int) $fixtureLinkCountStatement->fetchColumn() === 1
    );

    $check(
        'One account holder cannot silently link to a second supplier',
        $expectFailure(
            static fn () => $service->linkAccountToSupplier($accountIds[0], $supplierIds[1], $adminUserId),
            'already linked to supplier'
        )
    );
    $check(
        'One supplier cannot silently link to a second account holder',
        $expectFailure(
            static fn () => $service->linkAccountToSupplier($accountIds[1], $supplierIds[0], $adminUserId),
            'already linked to account holder'
        )
    );
    $check(
        'A non-administrator cannot create a link',
        $expectFailure(
            static fn () => $service->linkAccountToSupplier($accountIds[1], $supplierIds[1], 0),
            'Only a financial administrator'
        )
    );

    $linkId = (int) ($link['id'] ?? 0);
    $unlinkResult = $service->unlinkAccountFromSupplier(
        $linkId,
        $adminUserId,
        'Regression unlink confirmation.'
    );
    $history = $repository->historyForBusinessSource($accountIds[0]);
    $check(
        'Unlink removes the active bridge but preserves permanent history',
        ($unlinkResult['action'] ?? '') === 'unlinked'
            && $repository->findByBusinessSourceId($accountIds[0]) === null
            && count($history) === 2
            && (string) ($history[0]['action'] ?? '') === 'unlinked'
            && (string) ($history[1]['action'] ?? '') === 'linked',
        json_encode($history, JSON_THROW_ON_ERROR)
    );

    $financialCountsAfter = [
        'journal_entries' => (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'customer_receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'customer_receivables' => (int) $db->query('SELECT COUNT(*) FROM customer_receivable_items')->fetchColumn(),
        'supplier_obligations' => (int) $db->query('SELECT COUNT(*) FROM supplier_obligations')->fetchColumn(),
    ];
    $check(
        'Linking and unlinking have zero financial side effects',
        $financialCountsAfter === $financialCountsBefore,
        json_encode(['before' => $financialCountsBefore, 'after' => $financialCountsAfter], JSON_THROW_ON_ERROR)
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Account holder–supplier link foundation regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Account holder–supplier link foundation regression passed.' . PHP_EOL;
return 0;
