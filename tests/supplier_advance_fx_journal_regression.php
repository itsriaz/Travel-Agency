<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$db->beginTransaction();
try {
    $booking = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => date('Y-m-d'),
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Advance FX Journal Rollback Fixture',
    ], 1, [1, 2])['booking'];
    $service = (new \App\Services\ServiceWorkspaceService($app))->saveService([
        'booking_id' => (int) $booking['id'], 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED',
        'supplier_name' => 'Advance FX Journal Fixture ' . random_int(100000, 999999),
        'sale_price' => 100, 'final_sale_price' => 120,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [1, 2])['service'];
    $suppliers = new \App\Repositories\SupplierRepository($app);
    $obligation = $suppliers->findObligationByServiceLine(
        (string) $booking['booking_reference'], (string) $service['line_reference']
    );
    $assert($obligation !== null && (float) $obligation['gross_amount'] === 100.0,
        'AED payable fixture was not created');
    $advanceId = $suppliers->registerAdvance([
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 1,
        'currency' => 'PKR', 'deposit_amount' => 8000, 'available_amount' => 8000,
        'reference_no' => 'ROLLBACK-FX-ADVANCE', 'received_at' => date('Y-m-d'),
        'actor_user_id' => 1,
    ]);
    (new \App\Services\SupplierSettlementWorkspaceService($app))->applyCrossCurrencySupplierAdvance([
        'booking_id' => (int) $booking['id'],
        'supplier_advance_id' => $advanceId,
        'supplier_obligation_id' => (int) $obligation['id'],
        'advance_apply_amount' => 100,
        'exchange_rate' => 0.0125,
        'exchange_rate_effective_date' => '2035-01-01',
        'application_note' => 'Rollback-only FX journal fixture',
    ], 1, [1, 2]);

    $reference = 'SADV-FX-' . $advanceId . '-' . $obligation['id'];
    $statement = $db->prepare('SELECT je.source_reference, je.branch_id, je.booking_reference, je.currency,
            coa.code, jel.debit_amount, jel.credit_amount
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.source_reference IN (?, ?, ?) ORDER BY je.id, jel.id');
    $statement->execute([$reference . '-SOURCE', $reference . '-DESTINATION', $reference]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($rows) === 6, 'Cross-currency advance requires three balanced two-line journals');
    $byReference = [];
    foreach ($rows as $row) {
        $byReference[$row['source_reference']][] = $row;
        $assert(! str_starts_with((string) $row['code'], 'CASH_')
            && ! str_starts_with((string) $row['code'], 'BANK_'),
            'Supplier advance FX application must not move cash');
    }
    $source = $byReference[$reference . '-SOURCE'] ?? [];
    $destination = $byReference[$reference . '-DESTINATION'] ?? [];
    $application = $byReference[$reference] ?? [];
    $assert(count($source) === 2 && (int) $source[0]['branch_id'] === 1
        && $source[0]['currency'] === 'PKR' && $source[0]['booking_reference'] === null
        && abs(array_sum(array_column($source, 'debit_amount')) - 8000) < .005
        && abs(array_sum(array_column($source, 'credit_amount')) - 8000) < .005,
        'PKR source advance was not cleared in its branch');
    $assert(count($destination) === 2 && (int) $destination[0]['branch_id'] === 2
        && $destination[0]['currency'] === 'AED' && $destination[0]['booking_reference'] === null
        && abs(array_sum(array_column($destination, 'debit_amount')) - 100) < .005
        && abs(array_sum(array_column($destination, 'credit_amount')) - 100) < .005,
        'AED supplier-pool credit was not established in the payable branch');
    $assert(count($application) === 2 && $application[0]['currency'] === 'AED'
        && $application[0]['booking_reference'] === $booking['booking_reference']
        && abs(array_sum(array_column($application, 'debit_amount')) - 100) < .005
        && abs(array_sum(array_column($application, 'credit_amount')) - 100) < .005,
        'Only the destination booking should carry the payable application');
    $assert(abs((float) $suppliers->findAdvanceById($advanceId)['available_amount']) < .005
        && abs((float) $suppliers->findObligationById((int) $obligation['id'])['net_payable_amount']) < .005,
        'Advance and payable balances did not follow the journals');

    echo '[PASS] Cross-currency supplier advance posts paired noncash pool FX journals and one payable application.' . PHP_EOL;
} finally {
    $db->rollBack();
}
