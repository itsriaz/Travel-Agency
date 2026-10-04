<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$statement = $db->prepare('SELECT b.id AS booking_id, b.branch_id, bs.id AS service_id, bs.supplier_id
    FROM bookings b
    INNER JOIN booking_services bs ON bs.booking_id = b.id
    WHERE b.booking_reference = :reference AND bs.line_reference = "SV-001"');
$statement->execute(['reference' => 'BK-001039']);
$booking = $statement->fetch(PDO::FETCH_ASSOC);
$assert(is_array($booking), 'BK-001039 service must exist.');

$supplierId = (int) $booking['supplier_id'];
$branchId = (int) $booking['branch_id'];
$serviceState = (new \App\Services\ServiceWorkspaceService($app))->serviceState((int) $booking['booking_id'], [$branchId]);
$sourceService = $serviceState['services'][0] ?? [];
$assert((float) ($sourceService['supplier_credit_recorded_source_amount'] ?? 0) === 150.0
    && (float) ($sourceService['supplier_refundable_credit_amount'] ?? -1) === 0.0,
    'The existing PKR 150 advance release must not be offered as a second supplier refund.');
$service = new \App\Services\SupplierLedgerService($app);
$bookingReport = $service->report([
    'bookingReference' => 'BK-001039',
    'supplierId' => $supplierId,
    'currency' => 'PKR',
], [$branchId]);
$rows = $bookingReport['rows'];
$bookingCards = array_column($bookingReport['summaryCards'], 'value', 'label');
$assert(($bookingCards['Advance Used / PKR'] ?? '') === 'PKR 200.00', 'Booking summary must show PKR 200 settled from advance.');
$assert(($bookingCards['Returned to Advance / PKR'] ?? '') === 'PKR 150.00', 'Booking summary must show PKR 150 returned to advance.');
$assert(($bookingCards['Supplier Balance / PKR'] ?? '') === 'PKR 0.00', 'Booking summary must close at zero.');
$expected = [
    ['Supplier invoice recorded', 200.0, 0.0, '200.00 Payable'],
    ['Supplier advance applied', 0.0, 200.0, '-'],
    ['Supplier invoice reversed on cancellation', 0.0, 200.0, '200.00 Advance'],
    ['Supplier cancellation charge', 50.0, 0.0, '150.00 Advance'],
    ['Supplier refund moved to advance', 150.0, 0.0, '-'],
];
$assert(count($rows) === count($expected), 'Booking ledger must contain the five evidenced settlement movements.');
foreach ($expected as $index => [$label, $debit, $credit, $balance]) {
    $row = $rows[$index];
    $assert(($row['entry_type'] ?? '') === $label, 'Unexpected supplier-ledger movement at row ' . ($index + 1) . '.');
    $assert(abs((float) ($row['raw_debit_amount'] ?? 0) - $debit) < 0.005, $label . ' debit differs.');
    $assert(abs((float) ($row['raw_credit_amount'] ?? 0) - $credit) < 0.005, $label . ' credit differs.');
    $assert(($row['balance_amount'] ?? '') === $balance, $label . ' balance differs.');
}

$transferJournalId = (int) ($rows[4]['journal_entry_id'] ?? 0);
$assert($transferJournalId > 0, 'Advance transfer must cite a posted journal.');
$statement = $db->prepare('SELECT COALESCE(SUM(jel.debit_amount), 0)
    FROM journal_entry_lines jel
    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE je.id = :journal_id AND je.source_type = "supplier_settlement_released"
      AND coa.code = "SUPPLIER_ADVANCES"');
$statement->execute(['journal_id' => $transferJournalId]);
$assert(abs((float) $statement->fetchColumn() - 150.0) < 0.005, 'The PKR 150 transfer must be backed by the supplier-advance journal.');

$statement = $db->prepare('SELECT gross_amount, advance_applied_amount, net_payable_amount, status
    FROM supplier_obligations
    WHERE booking_reference = :reference AND service_line_reference = "SV-001"');
$statement->execute(['reference' => 'BK-001039']);
$obligation = $statement->fetch(PDO::FETCH_ASSOC);
$assert(is_array($obligation)
    && (float) $obligation['gross_amount'] === 50.0
    && (float) $obligation['advance_applied_amount'] === 50.0
    && (float) $obligation['net_payable_amount'] === 0.0
    && $obligation['status'] === 'covered_by_advance', 'The PKR 50 supplier penalty must be fully settled by advance.');
$statement = $db->prepare('SELECT COALESCE(SUM(available_amount), 0)
    FROM supplier_advances WHERE supplier_id = :supplier_id AND branch_id = :branch_id AND currency = "PKR"');
$statement->execute(['supplier_id' => $supplierId, 'branch_id' => $branchId]);
$assert(abs((float) $statement->fetchColumn() - 950.0) < 0.005, 'Saved supplier advance must remain PKR 950.');

$accountReport = $service->report([
    'bookingReference' => '',
    'supplierId' => $supplierId,
    'currency' => 'PKR',
], [$branchId]);
$accountRows = $accountReport['rows'];
$assert(($accountRows[0]['entry_type'] ?? '') === 'Supplier advance paid', 'Same-day supplier funding must precede the booking invoice.');
$assert(($accountRows[0]['balance_amount'] ?? '') === '1,000.00 Advance', 'Supplier-wide ledger must open with the recorded advance.');
$assert(($accountRows[1]['balance_amount'] ?? '') === '800.00 Advance', 'Booking cost must consume PKR 200 of the supplier-wide advance.');
$assert(! in_array('Supplier refund moved to advance', array_column($accountRows, 'entry_type'), true), 'Full supplier account must not count the internal transfer twice.');
$assert(! in_array('Supplier advance applied', array_column($accountRows, 'entry_type'), true), 'Full supplier account must not count advance allocation as new cash.');
$assert(($accountRows[count($accountRows) - 1]['balance_amount'] ?? '') === '950.00 Advance', 'Supplier-wide closing advance must be PKR 950.');

echo '[PASS] BK-001039 invoice, advance application, cancellation, penalty, and credit transfer close at zero.' . PHP_EOL;
echo '[PASS] The PKR 150 transfer is journal-backed and supplier-wide advance remains PKR 950.' . PHP_EOL;
