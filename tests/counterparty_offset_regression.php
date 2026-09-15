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

    $db->prepare('INSERT INTO customer_receivable_items (branch_id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status, due_date, created_by_user_id) VALUES (:branch, :reference, "SV-002", "PKR", 2000, 0, 2000, "open", "2000-01-01", :user)')->execute(['branch' => $branchId, 'reference' => $saleBooking, 'user' => $actorId]);
    $unselectedReceivableId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, due_date, created_by_user_id) VALUES (:supplier, :branch, :reference, "SV-002", "PKR", 2000, 0, 2000, "open", "2000-01-01", :user)')->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $buyBooking, 'user' => $actorId]);
    $unselectedObligationId = (int) $db->lastInsertId();

    $service = new \App\Services\CounterpartyOffsetService($app);
    $validInput = ['link_id' => $linkId, 'branch_id' => $branchId, 'currency' => 'PKR', 'offset_date' => date('Y-m-d'), 'amount' => 600, 'customer_receivable_item_id' => $receivableId, 'supplier_obligation_id' => $obligationId];
    foreach ([
        'missing invoice' => ['customer_receivable_item_id' => 0],
        'wrong invoice' => ['customer_receivable_item_id' => PHP_INT_MAX],
        'wrong supplier invoice' => ['supplier_obligation_id' => PHP_INT_MAX],
        'zero amount' => ['amount' => 0],
        'negative amount' => ['amount' => -10],
        'too much' => ['amount' => 1001],
        'wrong currency' => ['currency' => 'AED'],
        'wrong branch' => ['branch_id' => PHP_INT_MAX],
    ] as $label => $override) {
        $beforeOffsets = (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets')->fetchColumn();
        $rejected = false;
        try { $service->settle(array_replace($validInput, $override), $actorId, [$branchId]); }
        catch (RuntimeException $exception) { $rejected = true; }
        $check('Rejects ' . $label . ' without posting', $rejected && $beforeOffsets === (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets')->fetchColumn());
    }
    $first = $service->settle(['link_id' => $linkId, 'branch_id' => $branchId, 'currency' => 'PKR', 'offset_date' => date('Y-m-d'), 'amount' => 600, 'customer_receivable_item_id' => $receivableId, 'supplier_obligation_id' => $obligationId], $actorId, [$branchId]);
    $receivable = $db->query('SELECT allocated_amount, outstanding_amount, status FROM customer_receivable_items WHERE id = ' . $receivableId)->fetch(PDO::FETCH_ASSOC);
    $payable = $db->query('SELECT net_payable_amount, status FROM supplier_obligations WHERE id = ' . $obligationId)->fetch(PDO::FETCH_ASSOC);
    $accountAllocated = (float) $db->query('SELECT SUM(allocated_amount) FROM counterparty_offset_account_allocations WHERE counterparty_offset_id = ' . (int) $first['id'])->fetchColumn();
    $check('Account-holder allocation records PKR 600', abs($accountAllocated - 600) <= 0.005);
    $check('Customer invoice remains fully outstanding with no customer allocation', abs((float) $receivable['outstanding_amount'] - 1000) <= 0.005 && abs((float) $receivable['allocated_amount']) <= 0.005 && $receivable['status'] === 'open');
    $check('Supplier payable is reduced by PKR 600', abs((float) $payable['net_payable_amount'] - 400) <= 0.005 && $payable['status'] === 'partially_covered');
    $check('Earlier unselected supplier invoice remains untouched', (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $unselectedObligationId)->fetchColumn() === 2000.0);
    $check('Earlier unselected account invoice has no adjustment', (int) $db->query('SELECT COUNT(*) FROM counterparty_offset_account_allocations WHERE customer_receivable_item_id = ' . $unselectedReceivableId)->fetchColumn() === 0);

    $customerRows = (new \App\Repositories\ReportRepository($app))->customerLedger([$branchId], null, null, 'PKR', $businessSourceId, '', $saleBooking);
    $check('Customer ledger contains no linked supplier settlement row', count(array_filter($customerRows, static fn (array $row): bool => ($row['row_type'] ?? '') === 'linked_party_offset')) === 0);
    $accountReport = (new \App\Services\AccountLedgerService($app))->report(['currency' => 'PKR', 'businessSourceId' => $businessSourceId, 'bookingReference' => $saleBooking], [$branchId]);
    $accountRows = array_values(array_filter((array) ($accountReport['rows'] ?? []), static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Account balance applied to linked supplier'));
    $check('Account ledger records the non-cash PKR 600 settlement', count($accountRows) === 1 && abs((float) ($accountRows[0]['raw_credit_amount'] ?? 0) - 600) <= 0.005);
    $supplierRows = (new \App\Repositories\ReportRepository($app))->supplierLedger([$branchId], null, null, 'PKR', '', $supplierId, 0, $buyBooking);
    $check('Supplier ledger records the linked account adjustment', count(array_filter($supplierRows, static fn (array $row): bool => ($row['entry_type'] ?? '') === 'Linked Account Balance Adjustment')) === 1);

    $second = $service->settle(['link_id' => $linkId, 'branch_id' => $branchId, 'currency' => 'PKR', 'offset_date' => date('Y-m-d'), 'amount' => 400, 'customer_receivable_item_id' => $receivableId, 'supplier_obligation_id' => $obligationId], $actorId, [$branchId]);
    $closed = $db->query('SELECT (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') customer_due, (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId . ') supplier_due')->fetch(PDO::FETCH_ASSOC);
    $check('Remaining PKR 400 closes supplier only while customer still owes PKR 1,000', abs((float) $second['amount'] - 400) <= 0.005 && abs((float) $closed['customer_due'] - 1000) <= 0.005 && abs((float) $closed['supplier_due']) <= 0.005);

    $service->void((int) $second['id'], 'QA reversal', $actorId, [$branchId]);
    $restored = $db->query('SELECT (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') customer_due, (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId . ') supplier_due')->fetch(PDO::FETCH_ASSOC);
    $check('Reversal restores supplier PKR 400 and never changes customer due', abs((float) $restored['customer_due'] - 1000) <= 0.005 && abs((float) $restored['supplier_due'] - 400) <= 0.005);
    $during = ['receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(), 'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(), 'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn()];
    $check('No customer receipt, supplier payment, or treasury movement is fabricated', $during === $before, json_encode(['before' => $before, 'during' => $during]));

    $partial = $service->settle(array_replace($validInput, ['amount' => 200]), $actorId, [$branchId]);
    $releaseStartId = (int) $db->query('SELECT MAX(id) FROM journal_entries')->fetchColumn();
    (new \App\Repositories\SupplierRepository($app))->releaseSettledCreditForObligation($obligationId, 700, $actorId, 'QA partial release');
    $partialNow = $db->query('SELECT amount FROM counterparty_offsets WHERE id=' . (int) $partial['id'])->fetchColumn();
    $releaseJournalId = (int) $db->query('SELECT MAX(id) FROM journal_entries WHERE id > ' . $releaseStartId . ' AND source_type="linked_party_offset_release"')->fetchColumn();
    $partialVoid = $service->void((int) $partial['id'], 'QA void remaining amount', $actorId, [$branchId]);
    $reversalId = (int) $partialVoid['reversal_journal_entry_id'];
    $reversalAmount = (float) $db->query('SELECT SUM(debit_amount) FROM journal_entry_lines WHERE journal_entry_id=' . $reversalId)->fetchColumn();
    $check('Partial release leaves PKR 100 and void reverses only PKR 100', (float) $partialNow === 100.0 && $reversalAmount === 100.0);
    $journalIds = implode(',', [(int) $partial['journal_entry_id'], $releaseJournalId, $reversalId]);
    $net = $db->query('SELECT account_id,SUM(debit_amount-credit_amount) AS net FROM journal_entry_lines WHERE journal_entry_id IN (' . $journalIds . ') GROUP BY account_id HAVING ABS(net)>0.005')->fetchAll();
    $check('Original plus partial release plus final void nets to zero per account', $releaseJournalId > 0 && $net === []);
    $check('Partial void restores only remaining supplier amount', (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id=' . $obligationId)->fetchColumn() === 400.0);
    $customerPartial = $service->settle(array_replace($validInput, ['amount' => 200]), $actorId, [$branchId]);
    $laterVoid = $service->settle(array_replace($validInput, ['amount' => 100]), $actorId, [$branchId]);
    $service->void((int) $laterVoid['id'], 'QA preserve void history', $actorId, [$branchId]);
    $releaseStartId = (int) $db->query('SELECT MAX(id) FROM journal_entries')->fetchColumn();
    (new \App\Repositories\CustomerPaymentRepository($app))->releaseAllocatedCreditForReceivable($receivableId, 700, $actorId, 'QA customer-side partial release');
    $releaseJournalId = (int) $db->query('SELECT MAX(id) FROM journal_entries WHERE id > ' . $releaseStartId . ' AND source_type="linked_party_offset_release"')->fetchColumn();
    $check('Customer-side release reopens supplier PKR 100', (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id=' . $obligationId)->fetchColumn() === 300.0);
    $check('Customer release ignores a newer void settlement', (float) $db->query('SELECT amount FROM counterparty_offsets WHERE id=' . (int) $laterVoid['id'])->fetchColumn() === 100.0);
    $customerVoid = $service->void((int) $customerPartial['id'], 'QA remaining customer-side reversal', $actorId, [$branchId]);
    $journalIds = implode(',', [(int) $customerPartial['journal_entry_id'], $releaseJournalId, (int) $customerVoid['reversal_journal_entry_id']]);
    $net = $db->query('SELECT account_id,SUM(debit_amount-credit_amount) AS net FROM journal_entry_lines WHERE journal_entry_id IN (' . $journalIds . ') GROUP BY account_id HAVING ABS(net)>0.005')->fetchAll();
    $check('Customer-side release and void net to zero in accounting', $net === []);
    $check('Customer-side release and void restore supplier PKR 400', (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id=' . $obligationId)->fetchColumn() === 400.0);
    $fullyReleased = $service->settle(array_replace($validInput, ['amount' => 200]), $actorId, [$branchId]);
    (new \App\Repositories\SupplierRepository($app))->releaseSettledCreditForObligation($obligationId, 600, $actorId, 'QA full release');
    $journalCount = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $fullyVoided = $service->void((int) $fullyReleased['id'], 'QA close fully released adjustment', $actorId, [$branchId]);
    $check('Fully released adjustment closes without another journal or balance change', $fullyVoided['status'] === 'void'
        && $fullyVoided['reversal_journal_entry_id'] === null
        && $journalCount === (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn()
        && (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id=' . $obligationId)->fetchColumn() === 400.0);
    $duplicateRejected = false;
    try { $service->void((int) $fullyReleased['id'], 'QA duplicate void', $actorId, [$branchId]); }
    catch (RuntimeException $exception) { $duplicateRejected = true; }
    $check('Repeated void is rejected', $duplicateRejected);
    $check('All release/reversal cases leave passenger paid and due unchanged', (float) $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id=' . $receivableId)->fetchColumn() === 1000.0
        && (float) $db->query('SELECT allocated_amount FROM customer_receivable_items WHERE id=' . $receivableId)->fetchColumn() === 0.0);
    $historyRepository = new \App\Repositories\LinkedSettlementHistoryRepository($app);
    $historyService = new \App\Services\LinkedSettlementHistoryService($app);
    $historyFilters = ['businessSourceId' => $businessSourceId, 'supplierId' => $supplierId, 'currency' => 'PKR'];
    $history = $historyRepository->reversed([$branchId], $historyFilters, 'account');
    $historyById = array_column($history, null, 'id');
    $check('Voided history includes all five reversals, but not the active PKR 600 adjustment', count($history) === 5 && ! isset($historyById[$first['id']]));
    $check('Partial history preserves original PKR 200 separately from remaining and reversed PKR 100',
        (float) $historyById[$partial['id']]['original_amount'] === 200.0
        && (float) $historyById[$partial['id']]['remaining_at_void'] === 100.0
        && (float) $historyById[$partial['id']]['reversal_amount'] === 100.0);
    $check('Fully released history survives deleted allocation rows without inventing a void journal',
        (float) $historyById[$fullyReleased['id']]['original_amount'] === 200.0
        && (float) $historyById[$fullyReleased['id']]['remaining_at_void'] === 0.0
        && $historyById[$fullyReleased['id']]['reversal_journal_entry_id'] === null);
    foreach (['account' => $saleBooking, 'supplier' => $buyBooking] as $side => $booking) {
        $filteredHistory = $historyRepository->reversed([$branchId], $historyFilters + ['bookingReference' => $booking], $side);
        $check(ucfirst($side) . ' history uses saved audit invoice IDs after full release', count($filteredHistory) === 5);
        $check(ucfirst($side) . ' history excludes unrelated bookings', $historyRepository->reversed([$branchId], $historyFilters + ['bookingReference' => 'QA-UNRELATED'], $side) === []);
    }
    $check('History fails closed for empty and inaccessible branch scopes',
        $historyRepository->reversed([], $historyFilters, 'account') === []
        && $historyRepository->reversed([PHP_INT_MAX], $historyFilters, 'supplier') === []);
    $check('History keeps currencies and linked parties isolated',
        $historyRepository->reversed([$branchId], array_replace($historyFilters, ['currency' => 'AED']), 'account') === []
        && $historyRepository->reversed([$branchId], array_replace($historyFilters, ['businessSourceId' => PHP_INT_MAX]), 'account') === []
        && $historyRepository->reversed([$branchId], array_replace($historyFilters, ['supplierId' => PHP_INT_MAX]), 'supplier') === []);
    $check('History applies customer and airline filters instead of leaking unrelated headers',
        $historyRepository->reversed([$branchId], $historyFilters + ['customerName' => 'QA absent customer'], 'account') === []
        && $historyRepository->reversed([$branchId], $historyFilters + ['airline' => 'QA absent airline'], 'supplier') === []);
    $check('History booking filter binds SQL input', $historyRepository->reversed([$branchId], $historyFilters + ['bookingReference' => '" OR 1=1 --'], 'account') === []);

    // A date window must contain one actual event, not merely fall between the two dates.
    $db->prepare('UPDATE counterparty_offsets SET offset_date="2000-01-01" WHERE id=?')->execute([$second['id']]);
    $originalDateHistory = $historyRepository->reversed([$branchId], $historyFilters + ['dateFrom' => '2000-01-01', 'dateTo' => '2000-01-01'], 'account');
    $check('Original-date filter includes the paired later reversal', count($originalDateHistory) === 1 && (int) $originalDateHistory[0]['id'] === (int) $second['id']);
    $check('Void-date filter includes original older adjustments', count($historyRepository->reversed([$branchId], $historyFilters + ['dateFrom' => date('Y-m-d'), 'dateTo' => date('Y-m-d')], 'account')) === 5);
    $check('Date window between original and void excludes unrelated events', $historyRepository->reversed([$branchId], $historyFilters + ['dateFrom' => '2001-01-01', 'dateTo' => '2001-01-31'], 'account') === []);

    $formattedHistory = $historyService->report([$branchId], $historyFilters, 'account');
    $fullReleaseRows = array_values(array_filter($formattedHistory['rows'], static fn (array $row): bool => $row['offset_id'] === (int) $fullyReleased['id']));
    $check('History displays original and void separately, with zero additional reversal after full release',
        count($formattedHistory['rows']) === 10 && $fullReleaseRows[0]['amount'] === '200'
        && $fullReleaseRows[1]['amount'] === '0' && str_contains($fullReleaseRows[1]['detail'], 'Already fully released'));
    $accountAfterHistory = (new \App\Services\AccountLedgerService($app))->report(['currency' => 'PKR', 'businessSourceId' => $businessSourceId, 'bookingReference' => $saleBooking], [$branchId]);
    $check('Reversal history never enters account ledger balances or summary calculations', $accountAfterHistory === $accountReport);
    $supplierAfterHistory = (new \App\Repositories\ReportRepository($app))->supplierLedger([$branchId], null, null, 'PKR', '', $supplierId, 0, $buyBooking);
    $check('Reversal history never reactivates voided supplier allocations', count(array_filter($supplierAfterHistory, static fn (array $row): bool => ($row['entry_type'] ?? '') === 'Linked Account Balance Adjustment')) === 1);
    $linkedSettlementHistory = $formattedHistory;
    $linkedSettlementHistory['rows'][0]['detail'] = '<script>alert("test")</script>';
    ob_start();
    require BASE_PATH . '/app/Views/reports/partials/linked_settlement_history.php';
    $historyHtml = (string) ob_get_clean();
    $check('History view renders both entries and escapes stored text', str_contains($historyHtml, 'Original adjustment (now void)')
        && str_contains($historyHtml, 'Void / reversal') && ! str_contains($historyHtml, '<script>') && str_contains($historyHtml, '&lt;script&gt;'));
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

if ($failures !== []) { fwrite(STDERR, 'Regression failed: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo 'Account-holder and supplier offset regression passed.' . PHP_EOL;
