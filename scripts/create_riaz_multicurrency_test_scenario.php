<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$apply = in_array('--apply', $argv ?? [], true);
$marker = 'RIAZ-MULTI-CURRENCY-DEMO-20260723';
$today = date('Y-m-d');
$branchId = 1;
$actorUserId = 1;
$accessibleBranchIds = [1, 2];

echo 'Riaz five-booking multi-currency scenario' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY - DATA WILL REMAIN' : 'DRY RUN') . PHP_EOL;
echo 'Marker: ' . $marker . PHP_EOL . PHP_EOL;

$existing = $db->prepare(
    'SELECT id, booking_reference
     FROM bookings
     WHERE remarks LIKE :marker
     ORDER BY id'
);
$existing->execute(['marker' => '%' . $marker . '%']);
$existingBookings = $existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($existingBookings !== []) {
    echo 'This persistent scenario already exists:' . PHP_EOL;
    foreach ($existingBookings as $booking) {
        echo ' - ' . (string) $booking['booking_reference'] . ' (ID ' . (int) $booking['id'] . ')' . PHP_EOL;
    }
    exit(2);
}

$cases = [
    'ABC1' => [
        'invoice_currency' => 'PKR',
        'service_currency' => 'PKR',
        'service_amount' => 0.00,
        'pricing_rate' => 1.0,
        'service_rate' => 1.0,
        'payment_currency' => 'AED',
        'payment_amount' => 12.50,
        'payment_rate' => 0.0125,
    ],
    'ABC2' => [
        'invoice_currency' => 'PKR',
        'service_currency' => 'PKR',
        'service_amount' => 0.00,
        'pricing_rate' => 1.0,
        'service_rate' => 1.0,
        'payment_currency' => 'PKR',
        'payment_amount' => 1000.00,
        'payment_rate' => 1.0,
    ],
    'ABC3' => [
        'invoice_currency' => 'AED',
        'service_currency' => 'AED',
        'service_amount' => 987.50,
        'pricing_rate' => 0.0125,
        'service_rate' => 1.0,
        'payment_currency' => 'AED',
        'payment_amount' => 1000.00,
        'payment_rate' => 1.0,
    ],
    'ABC4' => [
        'invoice_currency' => 'USD',
        'service_currency' => 'PKR',
        'service_amount' => 319000.00,
        'pricing_rate' => 0.003125,
        'service_rate' => 0.003125,
        'payment_currency' => 'PKR',
        'payment_amount' => 320000.00,
        'payment_rate' => 320.0,
    ],
    'ABC5' => [
        'invoice_currency' => 'AED',
        'service_currency' => 'USD',
        'service_amount' => 246.88,
        'pricing_rate' => 0.0125,
        'service_rate' => 4.0,
        'payment_currency' => 'USD',
        'payment_amount' => 250.00,
        'payment_rate' => 0.25,
    ],
];

if (! $apply) {
    echo 'Planned permanent records:' . PHP_EOL;
    echo ' - Account holder: Riaz' . PHP_EOL;
    echo ' - Supplier: XYZ' . PHP_EOL;
    echo ' - Five PKR 1,000 supplier obligations and five customer invoices of 1,000 native units' . PHP_EOL;
    echo ' - Customer payments in PKR, AED, and USD through explicit exact-date FX allocations' . PHP_EOL;
    echo ' - Supplier payment: PKR 3,000 for ABC1, ABC2, and ABC3' . PHP_EOL;
    echo ' - ABC2 cancellation: PKR 400 supplier penalty, PKR 600 supplier refund, PKR 600 customer refund' . PHP_EOL;
    echo 'Dry run complete. Re-run with --apply to create and retain the records.' . PHP_EOL;
    exit(0);
}

$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$travelerWorkspace = new \App\Services\TravelerWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$supplierSettlement = new \App\Services\SupplierSettlementWorkspaceService($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);
$exchangeRates = new \App\Repositories\ExchangeRateRepository($app);
$customerPayments = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$accountLedger = new \App\Services\AccountLedgerService($app);
$supplierLedger = new \App\Services\SupplierLedgerService($app);

$scenario = [];
$businessSourceId = 0;
$supplierId = 0;
$supplierPaymentId = 0;
$treasuryIds = [];
$creationCommitted = false;

$db->beginTransaction();
try {
    $sourceStatement = $db->prepare('SELECT id FROM business_sources WHERE LOWER(name) = "riaz" LIMIT 1');
    $sourceStatement->execute();
    $businessSourceId = (int) ($sourceStatement->fetchColumn() ?: 0);
    if ($businessSourceId <= 0) {
        $insertSource = $db->prepare(
            'INSERT INTO business_sources (code, name, description, is_system, is_active)
             VALUES (:code, "Riaz", :description, 0, 1)'
        );
        $insertSource->execute([
            'code' => 'riaz',
            'description' => 'Persistent multi-currency account-ledger verification account.',
        ]);
        $businessSourceId = (int) $db->lastInsertId();
    }

    foreach (['PKR', 'AED', 'USD'] as $currency) {
        $accountName = 'Riaz Test Cash ' . $currency;
        $accountStatement = $db->prepare(
            'SELECT id FROM treasury_accounts
             WHERE branch_id = :branch_id AND account_name = :account_name AND currency = :currency
             LIMIT 1'
        );
        $accountStatement->execute([
            'branch_id' => $branchId,
            'account_name' => $accountName,
            'currency' => $currency,
        ]);
        $accountId = (int) ($accountStatement->fetchColumn() ?: 0);
        if ($accountId <= 0) {
            $accountId = $treasuryRepository->saveAccount([
                'branch_id' => $branchId,
                'account_type' => 'cash',
                'account_name' => $accountName,
                'currency' => $currency,
                'opening_balance' => 1000000.00,
                'opening_balance_date' => $today,
                'is_active' => 1,
                'notes' => $marker . ' test treasury account',
                'created_by_user_id' => $actorUserId,
            ], $accessibleBranchIds);
        }
        $treasuryIds[$currency] = $accountId;
    }

    foreach ([
        ['AED', 'PKR', 80.0],
        ['PKR', 'AED', 0.0125],
        ['USD', 'PKR', 320.0],
        ['PKR', 'USD', 0.003125],
        ['USD', 'AED', 4.0],
        ['AED', 'USD', 0.25],
    ] as [$fromCurrency, $toCurrency, $rate]) {
        $exchangeRates->upsertDailyRate(
            $fromCurrency,
            $toCurrency,
            $today,
            $rate,
            $branchId,
            $actorUserId
        );
    }

    foreach ($cases as $customer => $case) {
        $travelerResult = $travelerWorkspace->saveTraveler([
            'booking_id' => 0,
            'traveler_branch_id' => $branchId,
            'traveler_role' => 'lead',
            'first_name' => $customer,
            'last_name' => '',
            'full_name' => $customer,
            'gender' => 'unspecified',
            'mobile' => '',
            'passport_number' => '',
            'notes' => $marker . ' formal customer profile',
        ], $actorUserId, $accessibleBranchIds);
        $traveler = (array) ($travelerResult['traveler'] ?? []);
        $travelerId = (int) ($traveler['id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Customer profile creation failed for ' . $customer . '.');
        }

        $bookingResult = $bookingWorkspace->saveBooking([
            'branch_id' => $branchId,
            'business_source_id' => $businessSourceId,
            'selected_customer_id' => $travelerId,
            'booking_status' => 'draft',
            'booking_date' => $today,
            'due_date' => $today,
            'party_label' => 'Lead Traveler / Booking Party',
            'lead_traveler_name' => $customer,
            'contact_mobile' => '',
            'passport_number' => '',
            'remarks' => $marker . ' / ' . $customer,
        ], $actorUserId, $accessibleBranchIds);
        $booking = (array) ($bookingResult['booking'] ?? []);
        $bookingId = (int) ($booking['id'] ?? 0);
        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        if ($bookingId <= 0 || $bookingReference === '') {
            throw new RuntimeException('Booking creation failed for ' . $customer . '.');
        }

        $serviceResult = $serviceWorkspace->saveService([
            'booking_id' => $bookingId,
            'service_type' => 'air ticket',
            'service_status' => 'Open',
            'currency' => $case['invoice_currency'],
            'cost_currency' => 'PKR',
            'pricing_exchange_rate' => (string) $case['pricing_rate'],
            'pricing_rate_effective_date' => $today,
            'service_charge_currency' => $case['service_currency'],
            'service_charge_exchange_rate' => (string) $case['service_rate'],
            'service_charge_rate_effective_date' => $today,
            'supplier_name' => 'XYZ',
            'service_passenger_name' => $customer,
            'sale_price' => '1000.00',
            'purchase_cost' => '1000.00',
            'taxes' => '0.00',
            'other_fare' => '0.00',
            'soto_fare' => '0.00',
            'spyi_amount' => '0.00',
            'aq_yr_pk_amount' => '0.00',
            'yq_amount' => '0.00',
            'oth_amount' => '0.00',
            'vat_input' => '0.00',
            'vat' => '0.00',
            'commission' => '0.00',
            'service_charge' => number_format((float) $case['service_amount'], 2, '.', ''),
            'discount_amount' => '0.00',
            'final_sale_price' => '1000.00',
            'due_date' => $today,
            'remarks' => $marker . ' mixed-currency invoice for ' . $customer,
            'ticket_pnr' => 'RIAZ' . substr($customer, -1) . 'FX',
            'ticket_number' => 'RIAZ-' . $customer . '-1000',
            'ticket_airline' => 'XYZ',
            'ticket_sector_from' => 'ISB',
            'ticket_sector_to' => 'DXB',
            'ticket_departure_date' => $today,
            'ticket_return_date' => '',
            'ticket_class' => 'Economy',
            'ticket_fare' => '1000.00',
            'ticket_tax' => '0.00',
            'ticket_vat' => '0.00',
            'ticket_commission' => '0.00',
            'ticket_remarks' => $marker,
        ], $actorUserId, $accessibleBranchIds);
        $service = (array) ($serviceResult['service'] ?? []);
        $serviceId = (int) ($service['id'] ?? 0);
        $lineReference = (string) ($service['line_reference'] ?? '');
        $supplierId = (int) ($service['supplier_id'] ?? $supplierId);
        $receivable = $customerPayments->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        if (
            $serviceId <= 0
            || $receivable === null
            || $obligation === null
            || round((float) ($receivable['due_amount'] ?? 0), 2) !== 1000.00
            || round((float) ($obligation['gross_amount'] ?? 0), 2) !== 1000.00
        ) {
            throw new RuntimeException('Financial positions were not created correctly for ' . $customer . '.');
        }

        $receiptInput = [
            'booking_id' => $bookingId,
            'receipt_date' => $today,
            'receipt_currency' => $case['payment_currency'],
            'received_amount' => number_format((float) $case['payment_amount'], 2, '.', ''),
            'payment_method' => 'cash',
            'treasury_account_id' => $treasuryIds[$case['payment_currency']],
            'receipt_status' => 'received',
            'charges_amount' => '0.00',
            'receipt_remarks' => $marker . ' customer payment for ' . $customer,
        ];
        if ($case['payment_currency'] !== $case['invoice_currency']) {
            $receiptInput += [
                'settlement_mode' => 'exchange',
                'settlement_target_receivable_id' => (int) $receivable['id'],
                'settlement_target_currency' => $case['invoice_currency'],
                'settlement_target_receivable_amount' => '1000.00',
                'settlement_target_payment_amount' => number_format((float) $case['payment_amount'], 2, '.', ''),
                'settlement_rate_from_currency' => $case['invoice_currency'],
                'settlement_rate_to_currency' => $case['payment_currency'],
                'settlement_exchange_rate' => (string) $case['payment_rate'],
                'settlement_exchange_rate_effective_date' => $today,
            ];
        }
        $receiptResult = $receiptWorkspace->saveReceipt(
            $receiptInput,
            $actorUserId,
            $accessibleBranchIds
        );
        $receipt = (array) ($receiptResult['receipt'] ?? []);
        $receivableAfterPayment = $customerPayments->findReceivableByServiceLine($bookingReference, $lineReference);
        if (
            (int) ($receipt['id'] ?? 0) <= 0
            || $receivableAfterPayment === null
            || abs((float) ($receivableAfterPayment['outstanding_amount'] ?? 0)) > 0.005
        ) {
            throw new RuntimeException('Customer payment did not fully settle ' . $customer . '.');
        }

        $scenario[$customer] = [
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'service_id' => $serviceId,
            'line_reference' => $lineReference,
            'receivable_id' => (int) $receivable['id'],
            'obligation_id' => (int) $obligation['id'],
            'receipt_id' => (int) ($receipt['id'] ?? 0),
            'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
            'invoice_currency' => $case['invoice_currency'],
            'service_currency' => $case['service_currency'],
            'payment_currency' => $case['payment_currency'],
        ];
    }

    $supplierPayment = $supplierSettlement->recordGlobalPostpaidSupplierPayment([
        'branch_id' => $branchId,
        'supplier_id' => $supplierId,
        'global_supplier_obligation_id' => [
            $scenario['ABC1']['obligation_id'],
            $scenario['ABC2']['obligation_id'],
            $scenario['ABC3']['obligation_id'],
        ],
        'supplier_payment_currency' => 'PKR',
        'supplier_paid_amount' => '3000.00',
        'supplier_payment_date' => $today,
        'supplier_payment_method' => 'cash',
        'supplier_treasury_account_id' => $treasuryIds['PKR'],
        'supplier_reference_number' => 'RIAZ-XYZ-3000-' . date('His'),
        'supplier_payment_remarks' => $marker . ' pays ABC1, ABC2, and ABC3',
    ], $actorUserId, $accessibleBranchIds);
    $supplierPaymentId = (int) ($supplierPayment['payment']['id'] ?? 0);
    if (
        $supplierPaymentId <= 0
        || round((float) ($supplierPayment['allocated_amount'] ?? 0), 2) !== 3000.00
        || (int) ($supplierPayment['allocation_count'] ?? 0) !== 3
    ) {
        throw new RuntimeException('The PKR 3,000 supplier payment was not allocated to exactly three invoices.');
    }

    $serviceWorkspace->cancelService([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'cancel_reason' => $marker . ' ABC2 cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Paid ticket cancelled; supplier penalty PKR 400 and refund PKR 600.',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'settlement_reason' => $marker . ' cancellation settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '0.00',
        'expected_supplier_refund_amount' => '600.00',
        'agency_fee_refund_amount' => '0.00',
        'settlement_notes' => 'Supplier retains PKR 400; PKR 600 is due back to customer.',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->refundService([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'refund_reason' => $marker . ' supplier and customer cash refund',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => $treasuryIds['PKR'],
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => $treasuryIds['PKR'],
        'customer_refund_amount' => '600.00',
        'customer_refund_treatment' => 'pay_now',
        'customer_refund_payment_confirmed' => '1',
        'supplier_refund_amount' => '600.00',
        'refund_notes' => 'XYZ returned PKR 600 in cash and the same PKR 600 was paid to ABC2.',
    ], $actorUserId, $accessibleBranchIds);

    $db->commit();
    $creationCommitted = true;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] Scenario creation rolled back: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$failures = [];
$check = static function (bool $condition, string $label, string $detail = '') use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $condition) {
        $failures[] = $label;
    }
};

$check($creationCommitted, 'All scenario records were committed and retained');
$check(count($scenario) === 5, 'Five separate ABC customer bookings exist');

$refs = array_column($scenario, 'booking_reference');
$placeholders = implode(',', array_fill(0, count($refs), '?'));
$positionStatement = $db->prepare(
    "SELECT
        COUNT(*) service_count,
        SUM(CASE WHEN cri.due_amount = 1000.00 THEN 1 ELSE 0 END) exact_invoice_count,
        SUM(CASE WHEN so.gross_amount = 1000.00 THEN 1 ELSE 0 END) exact_supplier_invoice_count,
        SUM(cri.outstanding_amount) customer_outstanding,
        SUM(so.net_payable_amount) supplier_outstanding
     FROM booking_services bs
     INNER JOIN bookings b ON b.id = bs.booking_id
     INNER JOIN customer_receivable_items cri
        ON cri.booking_reference = b.booking_reference AND cri.service_line_reference = bs.line_reference
     INNER JOIN supplier_obligations so
        ON so.booking_reference = b.booking_reference AND so.service_line_reference = bs.line_reference
     WHERE b.booking_reference IN ({$placeholders})"
);
$positionStatement->execute($refs);
$positions = $positionStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    (int) ($positions['service_count'] ?? 0) === 5
        && (int) ($positions['exact_invoice_count'] ?? 0) === 4
        && round((float) ($positions['customer_outstanding'] ?? 0), 2) === 0.00,
    'All five customer invoices settled; cancelled ABC2 is correctly reduced to PKR 400',
    json_encode($positions, JSON_UNESCAPED_SLASHES)
);
$check(
    round((float) ($positions['supplier_outstanding'] ?? 0), 2) === 2000.00,
    'Exactly two PKR 1,000 supplier invoices remain payable',
    'PKR ' . number_format((float) ($positions['supplier_outstanding'] ?? 0), 2)
);

$cancelStatement = $db->prepare(
    'SELECT
        JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, "$.supplier_penalty_amount")) supplier_penalty,
        JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, "$.expected_supplier_refund_amount")) expected_supplier_refund,
        COALESCE(SUM(CASE WHEN r.event_type = "refund" AND r.event_status = "posted" THEN r.customer_refund_amount ELSE 0 END), 0) customer_refund,
        COALESCE(SUM(CASE WHEN r.event_type = "refund" AND r.event_status = "posted" THEN r.supplier_refund_amount ELSE 0 END), 0) supplier_refund
     FROM booking_service_events c
     LEFT JOIN booking_service_events r ON r.booking_service_id = c.booking_service_id AND r.id <> c.id
     WHERE c.booking_service_id = :service_id AND c.event_type = "cancel" AND c.event_status = "posted"
     GROUP BY c.id
     ORDER BY c.id DESC LIMIT 1'
);
$cancelStatement->execute(['service_id' => $scenario['ABC2']['service_id']]);
$cancelTruth = $cancelStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    round((float) ($cancelTruth['supplier_penalty'] ?? 0), 2) === 400.00
        && round((float) ($cancelTruth['expected_supplier_refund'] ?? 0), 2) === 600.00
        && round((float) ($cancelTruth['customer_refund'] ?? 0), 2) === 600.00
        && round((float) ($cancelTruth['supplier_refund'] ?? 0), 2) === 600.00,
    'ABC2 cancellation records PKR 400 penalty and both PKR 600 refund movements',
    json_encode($cancelTruth, JSON_UNESCAPED_SLASHES)
);

$accountReport = $accountLedger->report([
    'dateFrom' => $today,
    'dateTo' => $today,
    'currency' => '',
    'businessSourceId' => $businessSourceId,
    'customerName' => '',
    'bookingReference' => '',
], [$branchId]);
$profitCards = [];
foreach ((array) ($accountReport['summaryCards'] ?? []) as $card) {
    $profitCards[(string) ($card['label'] ?? '')] = (string) ($card['value'] ?? '');
}
$money = static function (string $value): float {
    $negative = str_contains($value, 'Loss');
    $numeric = preg_replace('/[^0-9.\-]/', '', $value) ?? '0';
    $amount = is_numeric($numeric) ? (float) $numeric : 0.0;
    return round($negative ? -abs($amount) : $amount, 2);
};
$check(
    $money($profitCards['Total Profit / AED'] ?? '0') === 1974.00
        && $money($profitCards['Total Profit / USD'] ?? '0') === 997.00
        && $money($profitCards['Total Profit / PKR'] ?? '0') === 0.00,
    'Riaz Account Ledger profit summary is separated by AED, PKR, and USD',
    json_encode($profitCards, JSON_UNESCAPED_SLASHES)
);

$supplierReport = $supplierLedger->report([
    'dateFrom' => $today,
    'dateTo' => $today,
    'currency' => 'PKR',
    'airline' => '',
    'supplierId' => $supplierId,
    'businessSourceId' => $businessSourceId,
    'bookingReference' => '',
], [$branchId]);
$supplierRows = (array) ($supplierReport['rows'] ?? []);
$supplierClosing = $supplierRows !== []
    ? round((float) ($supplierRows[array_key_last($supplierRows)]['raw_balance_amount'] ?? 0), 2)
    : 0.0;
$check(
    $supplierClosing === 2000.00,
    'XYZ Supplier Ledger closes with exactly PKR 2,000 payable',
    'PKR ' . number_format($supplierClosing, 2)
);

$imbalanceStatement = $db->query(
    'SELECT COUNT(*) FROM (
        SELECT journal_entry_id
        FROM journal_entry_lines
        GROUP BY journal_entry_id
        HAVING ABS(SUM(debit_amount) - SUM(credit_amount)) > 0.005
     ) broken'
);
$check((int) $imbalanceStatement->fetchColumn() === 0, 'Every journal entry remains balanced');

echo PHP_EOL . 'Persistent scenario references:' . PHP_EOL;
echo 'Account holder: Riaz (business source ID ' . $businessSourceId . ')' . PHP_EOL;
echo 'Supplier: XYZ (supplier ID ' . $supplierId . ')' . PHP_EOL;
echo 'Supplier payment ID: ' . $supplierPaymentId . PHP_EOL;
foreach ($scenario as $customer => $row) {
    echo sprintf(
        '%s: %s | invoice %s 1,000.00 | service amount %s | paid in %s | receipt %s',
        $customer,
        $row['booking_reference'],
        $row['invoice_currency'],
        $row['service_currency'],
        $row['payment_currency'],
        $row['receipt_no']
    ) . PHP_EOL;
}

if ($failures !== []) {
    echo PHP_EOL . 'RESULT: DATA RETAINED, BUT VERIFICATION FAILED:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RESULT: PASS - scenario data is retained for manual inspection.' . PHP_EOL;
exit(0);
