<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

echo 'Account-holder and supplier offset regression' . PHP_EOL;
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin") ORDER BY (r.code = "super_admin") DESC, u.id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) throw new RuntimeException('Active branch and financial administrator required.');

$before = [
    'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
    'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
    'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
];

$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')->execute(['code' => 'QA-LINK-' . $suffix, 'name' => 'QA Account ' . $suffix]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active) VALUES (:branch, :code, :name, "normal_payable", "PKR", 1)')->execute(['branch' => $branchId, 'code' => 'QA-SUP-' . $suffix, 'name' => 'QA Supplier ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id) VALUES (:business, :supplier, :user)')->execute(['business' => $businessSourceId, 'supplier' => $supplierId, 'user' => $actorId]);
    $linkId = (int) $db->lastInsertId();

    $saleBooking = 'QA-ACCOUNT-SALE-' . $suffix;
    $buyBooking = 'QA-SUPPLIER-BUY-' . $suffix;
    foreach ([[$saleBooking, $businessSourceId], [$buyBooking, $businessSourceId]] as [$reference, $sourceId]) {
        $db->prepare('INSERT INTO bookings (booking_reference, branch_id, business_source_id, booking_status, booking_date, created_by_user_id, updated_by_user_id) VALUES (:reference, :branch, :business, "open", CURDATE(), :created_user, :updated_user)')->execute(['reference' => $reference, 'branch' => $branchId, 'business' => $sourceId, 'created_user' => $actorId, 'updated_user' => $actorId]);
    }
    $db->prepare('INSERT INTO customer_receivable_items (branch_id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status, created_by_user_id) VALUES (:branch, :reference, "SV-001", "PKR", 1000, 0, 1000, "open", :user)')->execute(['branch' => $branchId, 'reference' => $saleBooking, 'user' => $actorId]);
    $receivableId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id) VALUES (:supplier, :branch, :reference, "SV-001", "PKR", 1000, 0, 1000, "open", :user)')->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $buyBooking, 'user' => $actorId]);
    $obligationId = (int) $db->lastInsertId();

    $service = new \App\Services\CounterpartyOffsetService($app);
    $first = $service->settle(['link_id' => $linkId, 'branch_id' => $branchId, 'currency' => 'PKR', 'offset_date' => date('Y-m-d'), 'amount' => 600], $actorId, [$branchId]);
    $receivable = $db->query('SELECT allocated_amount, outstanding_amount, status FROM customer_receivable_items WHERE id = ' . $receivableId)->fetch(PDO::FETCH_ASSOC);
    $payable = $db->query('SELECT net_payable_amount, status FROM supplier_obligations WHERE id = ' . $obligationId)->fetch(PDO::FETCH_ASSOC);
    $accountAllocated = (float) $db->query('SELECT SUM(allocated_amount) FROM counterparty_offset_account_allocations WHERE counterparty_offset_id = ' . (int) $first['id'])->fetchColumn();
    $check('Account-holder allocation records PKR 600', abs($accountAllocated - 600) <= 0.005);
    $check('Customer invoice remains fully outstanding with no customer allocation', abs((float) $receivable['outstanding_amount'] - 1000) <= 0.005 && abs((float) $receivable['allocated_amount']) <= 0.005 && $receivable['status'] === 'open');
    $check('Supplier payable is reduced by PKR 600', abs((float) $payable['net_payable_amount'] - 400) <= 0.005 && $payable['status'] === 'partially_covered');

    $customerRows = (new \App\Repositories\ReportRepository($app))->customerLedger([$branchId], null, null, 'PKR', $businessSourceId, '', $saleBooking);
    $check('Customer ledger contains no linked supplier settlement row', count(array_filter($customerRows, static fn (array $row): bool => ($row['row_type'] ?? '') === 'linked_party_offset')) === 0);
    $accountReport = (new \App\Services\AccountLedgerService($app))->report(['currency' => 'PKR', 'businessSourceId' => $businessSourceId, 'bookingReference' => $saleBooking], [$branchId]);
    $accountRows = array_values(array_filter((array) ($accountReport['rows'] ?? []), static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Account balance applied to linked supplier'));
    $check('Account ledger records the non-cash PKR 600 settlement', count($accountRows) === 1 && abs((float) ($accountRows[0]['raw_credit_amount'] ?? 0) - 600) <= 0.005);
    $supplierRows = (new \App\Repositories\ReportRepository($app))->supplierLedger([$branchId], null, null, 'PKR', '', $supplierId, 0, $buyBooking);
    $check('Supplier ledger records the linked account adjustment', count(array_filter($supplierRows, static fn (array $row): bool => ($row['entry_type'] ?? '') === 'Linked Account Balance Adjustment')) === 1);

    $second = $service->settle(['link_id' => $linkId, 'branch_id' => $branchId, 'currency' => 'PKR', 'offset_date' => date('Y-m-d'), 'amount' => 0], $actorId, [$branchId]);
    $closed = $db->query('SELECT (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') customer_due, (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId . ') supplier_due')->fetch(PDO::FETCH_ASSOC);
    $check('Remaining PKR 400 closes supplier only while customer still owes PKR 1,000', abs((float) $second['amount'] - 400) <= 0.005 && abs((float) $closed['customer_due'] - 1000) <= 0.005 && abs((float) $closed['supplier_due']) <= 0.005);

    $service->void((int) $second['id'], 'QA reversal', $actorId, [$branchId]);
    $restored = $db->query('SELECT (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') customer_due, (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId . ') supplier_due')->fetch(PDO::FETCH_ASSOC);
    $check('Reversal restores supplier PKR 400 and never changes customer due', abs((float) $restored['customer_due'] - 1000) <= 0.005 && abs((float) $restored['supplier_due'] - 400) <= 0.005);
    $during = ['receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(), 'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(), 'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn()];
    $check('No customer receipt, supplier payment, or treasury movement is fabricated', $during === $before, json_encode(['before' => $before, 'during' => $during]));
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

if ($failures !== []) { fwrite(STDERR, 'Regression failed: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo 'Account-holder and supplier offset regression passed.' . PHP_EOL;
