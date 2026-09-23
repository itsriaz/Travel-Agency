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
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Manual multi-invoice linked-party adjustment regression' . PHP_EOL;
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin") ORDER BY (r.code = "super_admin") DESC, u.id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('Active branch and financial administrator required.');
}

$protectedBefore = [
    'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
    'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
    'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
];
$automaticBefore = (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"')->fetchColumn();

$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')
        ->execute(['code' => 'QA-MULTI-' . $suffix, 'name' => 'QA Multi Account ' . $suffix]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active) VALUES (:branch, :code, :name, "normal_payable", "PKR", 1)')
        ->execute(['branch' => $branchId, 'code' => 'QA-MS-' . $suffix, 'name' => 'QA Multi Supplier ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id) VALUES (:business, :supplier, :user)')
        ->execute(['business' => $businessSourceId, 'supplier' => $supplierId, 'user' => $actorId]);
    $linkId = (int) $db->lastInsertId();

    $accountBooking = 'QA-MULTI-A-' . $suffix;
    $supplierBooking = 'QA-MULTI-S-' . $suffix;
    foreach ([$accountBooking, $supplierBooking] as $reference) {
        $db->prepare('INSERT INTO bookings (booking_reference, branch_id, business_source_id, booking_status, booking_date, created_by_user_id, updated_by_user_id) VALUES (:reference, :branch, :business, "open", CURDATE(), :created_user, :updated_user)')
            ->execute(['reference' => $reference, 'branch' => $branchId, 'business' => $businessSourceId, 'created_user' => $actorId, 'updated_user' => $actorId]);
    }

    $receivableIds = [];
    foreach ([1000, 800, 900] as $index => $amount) {
        $db->prepare('INSERT INTO customer_receivable_items (branch_id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status, created_by_user_id) VALUES (:branch, :reference, :line, "PKR", :due_amount, 0, :outstanding_amount, "open", :user)')
            ->execute(['branch' => $branchId, 'reference' => $accountBooking, 'line' => 'SV-00' . ($index + 1), 'due_amount' => $amount, 'outstanding_amount' => $amount, 'user' => $actorId]);
        $receivableIds[] = (int) $db->lastInsertId();
    }
    $obligationIds = [];
    foreach ([700, 600, 900] as $index => $amount) {
        $db->prepare('INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id) VALUES (:supplier, :branch, :reference, :line, "PKR", :gross_amount, 0, :net_payable_amount, "open", :user)')
            ->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $supplierBooking, 'line' => 'SV-00' . ($index + 1), 'gross_amount' => $amount, 'net_payable_amount' => $amount, 'user' => $actorId]);
        $obligationIds[] = (int) $db->lastInsertId();
    }

    $service = new \App\Services\CounterpartyOffsetService($app);
    $baseInput = [
        'link_id' => $linkId,
        'branch_id' => $branchId,
        'currency' => 'PKR',
        'offset_date' => date('Y-m-d'),
        'account_allocations' => [$receivableIds[0] => '400.00', $receivableIds[1] => '300.00', $receivableIds[2] => ''],
        'payable_allocations' => [$obligationIds[0] => '500.00', $obligationIds[1] => '200.00', $obligationIds[2] => '0'],
    ];
    foreach ([
        'unequal side totals' => ['payable_allocations' => [$obligationIds[0] => 500, $obligationIds[1] => 199]],
        'account amount above invoice availability' => ['account_allocations' => [$receivableIds[0] => 1001], 'payable_allocations' => [$obligationIds[0] => 700, $obligationIds[1] => 301]],
        'supplier amount above invoice availability' => ['account_allocations' => [$receivableIds[0] => 701], 'payable_allocations' => [$obligationIds[0] => 701]],
        'unrelated account invoice id' => ['account_allocations' => [PHP_INT_MAX => 100], 'payable_allocations' => [$obligationIds[0] => 100]],
        'negative item amount' => ['account_allocations' => [$receivableIds[0] => -1], 'payable_allocations' => [$obligationIds[0] => -1]],
    ] as $label => $override) {
        $before = (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets')->fetchColumn();
        $rejected = false;
        try {
            $service->settle(array_replace($baseInput, $override), $actorId, [$branchId]);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $check('Rejects ' . $label . ' atomically', $rejected && $before === (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets')->fetchColumn());
    }

    $offset = $service->settle($baseInput, $actorId, [$branchId]);
    $offsetId = (int) $offset['id'];
    $accountRows = $db->query('SELECT customer_receivable_item_id item_id, allocated_amount FROM counterparty_offset_account_allocations WHERE counterparty_offset_id=' . $offsetId . ' ORDER BY customer_receivable_item_id')->fetchAll(PDO::FETCH_ASSOC);
    $payableRows = $db->query('SELECT supplier_obligation_id item_id, allocated_amount FROM counterparty_offset_payable_allocations WHERE counterparty_offset_id=' . $offsetId . ' ORDER BY supplier_obligation_id')->fetchAll(PDO::FETCH_ASSOC);
    $check('One PKR 700 header stores two exact account invoice allocations', (float) $offset['amount'] === 700.0
        && array_map(static fn (array $row): float => (float) $row['allocated_amount'], $accountRows) === [400.0, 300.0]);
    $check('The same header stores two exact supplier invoice allocations', array_map(static fn (array $row): float => (float) $row['allocated_amount'], $payableRows) === [500.0, 200.0]);
    $check('Unselected account invoice remains untouched', (int) $db->query('SELECT COUNT(*) FROM counterparty_offset_account_allocations WHERE customer_receivable_item_id=' . $receivableIds[2])->fetchColumn() === 0);
    $check('Unselected supplier invoice remains untouched', (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id=' . $obligationIds[2])->fetchColumn() === 900.0);

    $customerState = $db->query('SELECT id,allocated_amount,outstanding_amount,status FROM customer_receivable_items WHERE id IN (' . implode(',', $receivableIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $supplierState = $db->query('SELECT id,net_payable_amount,status FROM supplier_obligations WHERE id IN (' . implode(',', $obligationIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('Passenger receivables remain open and unchanged', array_map(static fn (array $row): array => [(float) $row['allocated_amount'], (float) $row['outstanding_amount'], $row['status']], $customerState) === [[0.0, 1000.0, 'open'], [0.0, 800.0, 'open'], [0.0, 900.0, 'open']]);
    $check('Only chosen supplier payables are reduced by entered amounts', array_map(static fn (array $row): float => (float) $row['net_payable_amount'], $supplierState) === [200.0, 400.0, 900.0]);

    $journalRows = $db->query('SELECT a.code, SUM(l.debit_amount) debit, SUM(l.credit_amount) credit FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.journal_entry_id=' . (int) $offset['journal_entry_id'] . ' GROUP BY a.code ORDER BY a.code')->fetchAll(PDO::FETCH_ASSOC);
    $journalByCode = array_column($journalRows, null, 'code');
    $check('Accounting posts one balanced AP-to-AR PKR 700 journal', (float) ($journalByCode['AP_CONTROL']['debit'] ?? 0) === 700.0
        && (float) ($journalByCode['AR_CONTROL']['credit'] ?? 0) === 700.0);
    $check('No receipt, supplier payment, or treasury movement is fabricated', [
        'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
    ] === $protectedBefore);
    $check('Automatic adjustment remains disabled', $service->autoSettleLink($linkId, date('Y-m-d'), $actorId) === []
        && $automaticBefore === (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"')->fetchColumn());

    $voided = $service->void($offsetId, 'QA multi-invoice reversal', $actorId, [$branchId]);
    $restoredPayables = array_map('floatval', $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id IN (' . implode(',', $obligationIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $check('Reversal restores every selected supplier invoice', $voided['status'] === 'void' && $restoredPayables === [700.0, 600.0, 900.0]);
    $check('Reversal still does not alter passenger receivables', array_map('floatval', $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id IN (' . implode(',', $receivableIds) . ') ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)) === [1000.0, 800.0, 900.0]);
    $journalIds = (int) $offset['journal_entry_id'] . ',' . (int) $voided['reversal_journal_entry_id'];
    $netJournalRows = $db->query('SELECT account_id FROM journal_entry_lines WHERE journal_entry_id IN (' . $journalIds . ') GROUP BY account_id HAVING ABS(SUM(debit_amount-credit_amount)) > 0.005')->fetchAll(PDO::FETCH_COLUMN);
    $check('Original and reversal journals net to zero per control account', $netJournalRows === []);

    $db->exec('DELETE FROM counterparty_offset_account_allocations WHERE counterparty_offset_id=' . $offsetId);
    $db->exec('DELETE FROM counterparty_offset_payable_allocations WHERE counterparty_offset_id=' . $offsetId);
    $history = new \App\Repositories\LinkedSettlementHistoryRepository($app);
    $filters = ['businessSourceId' => $businessSourceId, 'supplierId' => $supplierId, 'currency' => 'PKR'];
    $check('Audit fallback keeps every account invoice discoverable after allocations are released', count($history->reversed([$branchId], $filters + ['bookingReference' => $accountBooking], 'account')) === 1);
    $check('Audit fallback keeps every supplier invoice discoverable after allocations are released', count($history->reversed([$branchId], $filters + ['bookingReference' => $supplierBooking], 'supplier')) === 1);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Manual multi-invoice linked-party adjustment regression passed.' . PHP_EOL;
