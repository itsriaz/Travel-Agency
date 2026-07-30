<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$customerPayments = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);

$today = date('Y-m-d');
$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$failures = [];
$check = static function (bool $ok, string $label, mixed $detail = null) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $ok) {
        $failures[] = $label;
    }
};

$journalBalances = static function (string $bookingReference, string $lineReference) use ($db): array {
    $statement = $db->prepare(
        'SELECT je.currency, coa.code,
                ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) net_balance
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.booking_reference = :booking_reference
           AND jel.service_line_reference = :line_reference
           AND coa.code IN ("AR_CONTROL", "SERVICE_REVENUE", "SERVICE_COST", "AP_CONTROL")
         GROUP BY je.currency, coa.code'
    );
    $statement->execute([
        'booking_reference' => $bookingReference,
        'line_reference' => $lineReference,
    ]);
    $result = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $result[(string) $row['currency']][(string) $row['code']] = round((float) $row['net_balance'], 2);
    }

    return $result;
};

echo 'Independent supplier, service-amount, invoice, and payment currency regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $customerStatement = $db->query(
        'SELECT id, full_name, mobile
         FROM travelers
         WHERE branch_id = 1
         ORDER BY id ASC
         LIMIT 1'
    );
    $customer = $customerStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($customer['id'] ?? 0) <= 0) {
        throw new RuntimeException('An active branch-1 customer is required for the payment-currency assertion.');
    }
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'selected_customer_id' => (int) $customer['id'],
        'lead_traveler_name' => (string) ($customer['full_name'] ?? 'Multi Currency Pricing Regression'),
        'contact_mobile' => (string) ($customer['mobile'] ?? ''),
        'remarks' => 'Rollback-only independent currency test',
    ], $actorUserId, $accessibleBranchIds);
    $booking = (array) ($bookingResult['booking'] ?? []);
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $firstResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01315789',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'USD',
        'service_charge_exchange_rate' => '3.67000000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Multi Currency Regression Supplier PKR',
        'service_passenger_name' => 'Multi Currency Passenger One',
        'sale_price' => '76000.00',
        'purchase_cost' => '76000.00',
        'taxes' => '0.00',
        'spyi_amount' => '0.00',
        'aq_yr_pk_amount' => '0.00',
        'yq_amount' => '0.00',
        'oth_amount' => '0.00',
        'vat_input' => '0.00',
        'vat' => '0.00',
        'commission' => '0.00',
        'service_charge' => '100.00',
        'discount_amount' => '0.00',
        'ticket_pnr' => 'MCFX01',
        'ticket_number' => 'MC-FX-001',
        'ticket_airline' => 'TEST AIR',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
    ], $actorUserId, $accessibleBranchIds);

    $firstService = (array) ($firstResult['service'] ?? []);
    $firstLineReference = (string) ($firstService['line_reference'] ?? '');
    $firstStored = $serviceRepository->findServiceById((int) ($firstService['id'] ?? 0));
    $firstReceivable = $customerPayments->findReceivableByServiceLine($bookingReference, $firstLineReference);
    $firstObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $firstLineReference);
    $firstJournals = $journalBalances($bookingReference, $firstLineReference);

    $check(
        ($firstStored['currency'] ?? '') === 'AED'
        && ($firstStored['cost_currency'] ?? '') === 'PKR'
        && ($firstStored['service_charge_currency'] ?? '') === 'USD'
        && abs((float) ($firstStored['pricing_exchange_rate'] ?? 0) - 0.01315789) <= 0.00000001
        && abs((float) ($firstStored['service_charge_exchange_rate'] ?? 0) - 3.67) <= 0.00000001,
        'Supplier cost, agency amount, and invoice currencies persist independently',
        $firstStored
    );
    $check(
        ($firstReceivable['currency'] ?? '') === 'AED'
        && abs((float) ($firstReceivable['due_amount'] ?? 0) - 1367.00) <= 0.005,
        'PKR supplier cost and USD agency amount convert into the exact AED invoice',
        $firstReceivable
    );
    $check(
        ($firstObligation['currency'] ?? '') === 'PKR'
        && abs((float) ($firstObligation['gross_amount'] ?? 0) - 76000.00) <= 0.005,
        'Supplier payable remains in its original PKR currency and nominal amount',
        $firstObligation
    );
    $check(
        abs((float) ($firstJournals['AED']['AR_CONTROL'] ?? 0) - 1367.00) <= 0.005
        && abs((float) ($firstJournals['AED']['SERVICE_REVENUE'] ?? 0) + 1367.00) <= 0.005
        && abs((float) ($firstJournals['PKR']['SERVICE_COST'] ?? 0) - 76000.00) <= 0.005
        && abs((float) ($firstJournals['PKR']['AP_CONTROL'] ?? 0) + 76000.00) <= 0.005,
        'AR/revenue use invoice currency while cost/AP use supplier currency',
        $firstJournals
    );

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => (int) ($firstService['id'] ?? 0),
        'corrected_invoice_currency' => 'AED',
        'corrected_cost_currency' => 'PKR',
        'corrected_service_charge_currency' => 'PKR',
        'corrected_cost_basis' => '76000.00',
        'corrected_service_charge' => '28000.00',
        'corrected_discount_amount' => '0.00',
        'corrected_pricing_exchange_rate' => '0.01315789',
        'corrected_pricing_rate_effective_date' => $today,
        'corrected_service_charge_exchange_rate' => '0.01315789',
        'corrected_service_charge_rate_effective_date' => $today,
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Verify agency amount currency correction updates every financial mirror',
    ], $actorUserId, $accessibleBranchIds);
    $firstStoredAfterCorrection = $serviceRepository->findServiceById((int) ($firstService['id'] ?? 0));
    $firstReceivableAfterCorrection = $customerPayments->findReceivableByServiceLine($bookingReference, $firstLineReference);
    $firstObligationAfterCorrection = $supplierRepository->findObligationByServiceLine($bookingReference, $firstLineReference);
    $firstJournalsAfterCorrection = $journalBalances($bookingReference, $firstLineReference);
    $check(
        ($firstStoredAfterCorrection['service_charge_currency'] ?? '') === 'PKR'
        && abs((float) ($firstStoredAfterCorrection['service_charge'] ?? 0) - 28000.00) <= 0.005
        && abs((float) ($firstStoredAfterCorrection['final_sale_price'] ?? 0) - 1368.00) <= 0.005
        && abs((float) ($firstReceivableAfterCorrection['due_amount'] ?? 0) - 1368.00) <= 0.005
        && abs((float) ($firstObligationAfterCorrection['gross_amount'] ?? 0) - 76000.00) <= 0.005
        && abs((float) ($firstJournalsAfterCorrection['AED']['AR_CONTROL'] ?? 0) - 1368.00) <= 0.005
        && abs((float) ($firstJournalsAfterCorrection['AED']['SERVICE_REVENUE'] ?? 0) + 1368.00) <= 0.005,
        'Later service-amount currency correction synchronizes service, AR, revenue, and subledgers',
        [
            'service' => $firstStoredAfterCorrection,
            'receivable' => $firstReceivableAfterCorrection,
            'obligation' => $firstObligationAfterCorrection,
            'journals' => $firstJournalsAfterCorrection,
        ]
    );

    $secondResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'USD',
        'cost_currency' => 'AED',
        'pricing_exchange_rate' => '0.27250000',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'PKR',
        'service_charge_exchange_rate' => '0.00350000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Multi Currency Regression Supplier AED',
        'service_passenger_name' => 'Multi Currency Passenger Two',
        'sale_price' => '1000.00',
        'purchase_cost' => '1000.00',
        'service_charge' => '5000.00',
        'vat' => '0.00',
        'discount_amount' => '0.00',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit',
        'visa_status' => 'Applied',
    ], $actorUserId, $accessibleBranchIds);

    $secondService = (array) ($secondResult['service'] ?? []);
    $secondLineReference = (string) ($secondService['line_reference'] ?? '');
    $secondStored = $serviceRepository->findServiceById((int) ($secondService['id'] ?? 0));
    $secondReceivable = $customerPayments->findReceivableByServiceLine($bookingReference, $secondLineReference);
    $secondObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $secondLineReference);

    $check(
        ($secondStored['currency'] ?? '') === 'USD'
        && ($secondStored['cost_currency'] ?? '') === 'AED'
        && ($secondStored['service_charge_currency'] ?? '') === 'PKR',
        'Reverse currency combination is accepted without fixed AED/PKR assumptions',
        $secondStored
    );
    $check(
        ($secondReceivable['currency'] ?? '') === 'USD'
        && abs((float) ($secondReceivable['due_amount'] ?? 0) - 291.00) <= 0.005
        && ($secondObligation['currency'] ?? '') === 'AED'
        && abs((float) ($secondObligation['gross_amount'] ?? 0) - 1000.00) <= 0.005,
        'AED supplier cost plus PKR agency amount produces the correct USD customer position',
        ['receivable' => $secondReceivable, 'obligation' => $secondObligation]
    );

    $precisionResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01250000',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1.00000000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Mixed Currency Invoice Composition Supplier',
        'service_passenger_name' => 'Mixed Currency Invoice Composition Passenger',
        'sale_price' => '1000.00',
        'purchase_cost' => '1000.00',
        'taxes' => '0.00',
        'spyi_amount' => '0.00',
        'aq_yr_pk_amount' => '0.00',
        'yq_amount' => '0.00',
        'oth_amount' => '0.00',
        'vat_input' => '0.00',
        'vat' => '0.00',
        'commission' => '0.00',
        'service_charge' => '20.00',
        'discount_amount' => '0.00',
        'ticket_pnr' => 'MCFX03',
        'ticket_number' => 'MC-FX-003',
        'ticket_airline' => 'TEST AIR',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
    ], $actorUserId, $accessibleBranchIds);
    $precisionService = (array) ($precisionResult['service'] ?? []);
    $precisionStored = $serviceRepository->findServiceById((int) ($precisionService['id'] ?? 0));
    $precisionReceivable = $customerPayments->findReceivableByServiceLine(
        $bookingReference,
        (string) ($precisionService['line_reference'] ?? '')
    );
    $precisionObligation = $supplierRepository->findObligationByServiceLine(
        $bookingReference,
        (string) ($precisionService['line_reference'] ?? '')
    );
    $check(
        abs((float) ($precisionStored['final_sale_price'] ?? 0) - 33.00) <= 0.005
        && abs((float) ($precisionStored['net_profit_loss'] ?? 0) - 20.00) <= 0.005
        && abs((float) ($precisionReceivable['due_amount'] ?? 0) - 33.00) <= 0.005
        && ($precisionObligation['currency'] ?? '') === 'PKR'
        && abs((float) ($precisionObligation['gross_amount'] ?? 0) - 1000.00) <= 0.005,
        'PKR 1,000 supplier cost plus AED 20 service amount produces AED 33 without dropping cost',
        [
            'service' => $precisionStored,
            'receivable' => $precisionReceivable,
            'obligation' => $precisionObligation,
        ]
    );

    $profitRepository = new \App\Repositories\ReportRepository($app);
    $precisionProfitRow = null;
    foreach ($profitRepository->serviceProfit([1], $today, $today) as $profitRow) {
        if (
            (string) ($profitRow['booking_reference'] ?? '') === $bookingReference
            && (string) ($profitRow['line_reference'] ?? '') === (string) ($precisionService['line_reference'] ?? '')
        ) {
            $precisionProfitRow = $profitRow;
            break;
        }
    }
    $check(
        is_array($precisionProfitRow)
        && ($precisionProfitRow['currency'] ?? '') === 'AED'
        && ($precisionProfitRow['payable_currency'] ?? '') === 'PKR'
        && abs((float) ($precisionProfitRow['receivable_amount'] ?? 0) - 33.00) <= 0.005
        && abs((float) ($precisionProfitRow['payable_original_amount'] ?? 0) - 1000.00) <= 0.005
        && abs((float) ($precisionProfitRow['payable_amount'] ?? 0) - 12.50) <= 0.005,
        'Service-profit reporting converts PKR supplier cost before subtracting it from an AED invoice',
        $precisionProfitRow
    );

    $pkrTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [];
    $paymentResult = $receiptWorkspace->saveReceipt([
        'settlement_mode' => 'exchange',
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'PKR',
        'received_amount' => '81480.00',
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($pkrTreasury['id'] ?? 0),
        'receipt_status' => 'received',
        'charges_amount' => '0.00',
        'settlement_target_receivable_id' => (int) ($secondReceivable['id'] ?? 0),
        'settlement_target_currency' => 'USD',
        'settlement_target_receivable_amount' => '291.00',
        'settlement_target_payment_amount' => '81480.00',
        'settlement_rate_from_currency' => 'USD',
        'settlement_rate_to_currency' => 'PKR',
        'settlement_exchange_rate' => '280.00000000',
        'settlement_exchange_rate_effective_date' => $today,
    ], $actorUserId, $accessibleBranchIds);
    $paymentReceipt = (array) ($paymentResult['receipt'] ?? []);
    $secondReceivableAfterPayment = $customerPayments->findReceivableByServiceLine($bookingReference, $secondLineReference);
    $check(
        ($paymentReceipt['currency'] ?? '') === 'PKR'
        && abs((float) ($paymentReceipt['received_amount'] ?? 0) - 81480.00) <= 0.005
        && abs((float) ($paymentReceipt['allocated_amount'] ?? 0) - 81480.00) <= 0.005
        && abs((float) ($secondReceivableAfterPayment['outstanding_amount'] ?? 0)) <= 0.005,
        'Customer payment currency remains independent and settles the USD invoice through explicit FX',
        ['receipt' => $paymentReceipt, 'receivable' => $secondReceivableAfterPayment]
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Independent multi-currency pricing regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Independent multi-currency pricing regression passed.' . PHP_EOL;
