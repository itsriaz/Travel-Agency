<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;
use App\Services\SupplierFoundationService;
use App\Services\SupplierSettlementWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};
$bookingRows = $db->query(
    'SELECT b.id AS booking_id, b.branch_id, o.id AS obligation_id, o.supplier_id, o.currency,
            o.net_payable_amount
     FROM supplier_obligations o
     INNER JOIN bookings b ON b.booking_reference = o.booking_reference
     WHERE o.booking_reference IN ("BK-001121", "BK-001122")
       AND o.net_payable_amount > 0
     ORDER BY o.booking_reference, o.id'
)->fetchAll(PDO::FETCH_ASSOC);
$advance = $db->query(
    'SELECT sa.id, sa.supplier_id, sa.currency, sa.available_amount
     FROM supplier_advances sa
     INNER JOIN supplier_payments sp ON sp.id = sa.source_supplier_payment_id
     WHERE sp.payment_no = "SPAY-000269" AND sp.status <> "void"
     ORDER BY sa.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (count($bookingRows) !== 2 || $advance === false) {
    echo '[SKIP] The two local test bookings and surviving advance are not present.', PHP_EOL;
    exit(0);
}
$assert((int) $bookingRows[0]['supplier_id'] === (int) $advance['supplier_id']
    && (int) $bookingRows[1]['supplier_id'] === (int) $advance['supplier_id']
    && (string) $bookingRows[0]['currency'] === (string) $advance['currency']
    && (string) $bookingRows[1]['currency'] === (string) $advance['currency'], 'Both payables match the surviving advance supplier and currency.');
$amounts = array_map(static fn (array $row): float => (float) $row['net_payable_amount'], $bookingRows);
$total = array_sum($amounts);
$assert((float) $advance['available_amount'] >= $total, 'The surviving advance can cover both payables.');
$actorId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$assert($actorId > 0, 'An active actor exists for the rollback-only posting test.');
$service = new SupplierSettlementWorkspaceService($app);
$advanceId = (int) $advance['id'];
$repository = new SupplierRepository($app);
$globalChoices = $repository->availableAdvanceRowsForSupplier((int) $advance['supplier_id'], (string) $advance['currency']);
$assert(in_array($advanceId, array_map(static fn (array $row): int => (int) $row['id'], $globalChoices), true), 'Supplier account screen can offer the surviving advance.');
foreach ($bookingRows as $row) {
    $preview = (new SupplierFoundationService($app))->buildWorkspacePreview((string) $db->query('SELECT booking_reference FROM bookings WHERE id = ' . (int) $row['booking_id'])->fetchColumn());
    $assert(in_array($advanceId, array_map(static fn (array $item): int => (int) $item['id'], $preview['advances']), true), 'Booking workspace can offer the surviving advance.');
}
$beforeTreasury = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
$beforeCustomer = $db->query('SELECT COALESCE(SUM(outstanding_amount), 0) FROM customer_receivable_items')->fetchColumn();
$beforeApplications = (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn();
$beforeJournals = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$lastJournalId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();

$db->beginTransaction();
try {
    foreach ($bookingRows as $index => $row) {
        $service->applySupplierAdvance([
            'booking_id' => (int) $row['booking_id'],
            'supplier_obligation_id' => (int) $row['obligation_id'],
            'supplier_advance_id' => $advanceId,
            'advance_apply_amount' => $amounts[$index],
        ], $actorId, [(int) $row['branch_id']]);
        $payable = (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . (int) $row['obligation_id'])->fetchColumn();
        $assert(abs($payable) < 0.005, 'Selected booking payable is covered by explicit advance application.');
    }
    $remaining = (float) $db->query('SELECT available_amount FROM supplier_advances WHERE id = ' . $advanceId)->fetchColumn();
    $assert(abs($remaining - ((float) $advance['available_amount'] - $total)) < 0.005, 'Advance balance decreases only by the two confirmed amounts.');
    $assert((int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn() === $beforeApplications + 2, 'Each payable has its own allocation history row.');
    $assert((int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $beforeJournals + 2, 'Each application posts a separate journal.');
    $journalTotals = $db->query(
        'SELECT je.id, je.currency, SUM(jel.debit_amount) AS debits, SUM(jel.credit_amount) AS credits
         FROM journal_entries je INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.id > ' . $lastJournalId . ' AND je.source_type = "supplier_advance_applied"
         GROUP BY je.id, je.currency'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($journalTotals) === 2 && count(array_filter($journalTotals,
        static fn (array $row): bool => abs((float) $row['debits'] - (float) $row['credits']) < 0.005
            && (string) $row['currency'] === (string) $advance['currency'])) === 2,
        'Both new application journals balance in the supplier currency.');
    $assert((int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $beforeTreasury, 'No treasury cash transaction is created by credit application.');
    $assert($db->query('SELECT COALESCE(SUM(outstanding_amount), 0) FROM customer_receivable_items')->fetchColumn() == $beforeCustomer, 'Customer receivables remain unchanged.');
} finally {
    $db->rollBack();
}

$db->beginTransaction();
try {
    $partialAmount = min(100.0, $amounts[0] / 2);
    $service->applySupplierAdvance([
        'booking_id' => (int) $bookingRows[0]['booking_id'],
        'supplier_obligation_id' => (int) $bookingRows[0]['obligation_id'],
        'supplier_advance_id' => $advanceId,
        'advance_apply_amount' => $partialAmount,
    ], $actorId, [(int) $bookingRows[0]['branch_id']]);
    $partialPayable = (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . (int) $bookingRows[0]['obligation_id'])->fetchColumn();
    $assert(abs($partialPayable - ($amounts[0] - $partialAmount)) < 0.005, 'A chosen partial amount leaves the rest of that payable open.');
} finally {
    $db->rollBack();
}

$db->beginTransaction();
try {
    $rejected = false;
    try {
        $service->applySupplierAdvance([
            'booking_id' => (int) $bookingRows[0]['booking_id'],
            'supplier_obligation_id' => (int) $bookingRows[0]['obligation_id'],
            'supplier_advance_id' => $advanceId,
            'advance_apply_amount' => $amounts[0] + 1,
        ], $actorId, [(int) $bookingRows[0]['branch_id']]);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'less available than requested');
    }
    $assert($rejected, 'Over-requesting a payable is rejected rather than silently applying less.');
} finally {
    $db->rollBack();
}

$db->beginTransaction();
try {
    $db->exec('UPDATE supplier_advances SET available_amount = 100 WHERE id = ' . $advanceId);
    $rejected = false;
    try {
        $service->applySupplierAdvance([
            'booking_id' => (int) $bookingRows[0]['booking_id'],
            'supplier_obligation_id' => (int) $bookingRows[0]['obligation_id'],
            'supplier_advance_id' => $advanceId,
            'advance_apply_amount' => 200,
        ], $actorId, [(int) $bookingRows[0]['branch_id']]);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'less available than requested');
    }
    $assert($rejected, 'A stale advance balance is rejected rather than silently applying a partial amount.');
} finally {
    $db->rollBack();
}

$assert((float) $db->query('SELECT available_amount FROM supplier_advances WHERE id = ' . $advanceId)->fetchColumn() === (float) $advance['available_amount'], 'Rollback preserves the original supplier advance.');
$assert((int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications')->fetchColumn() === $beforeApplications, 'Rollback leaves allocation history unchanged.');
$assert((int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $beforeJournals, 'Rollback leaves journals unchanged.');
