<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\AccountLedgerService($app);

$position = [
    'booking_id' => 38,
    'branch_id' => 2,
    'booking_reference' => 'BK-000038',
    'booking_date' => '2026-06-16',
    'lead_traveler_id' => 60,
    'business_source_name' => 'Abubakar AED sales',
    'customer_name' => 'Rizwan chacha',
    'service_type' => 'Air Ticket',
    'currency' => 'AED',
    'cost_currency' => 'PKR',
    'original_invoice_amount' => 1188,
    'current_customer_due' => 580,
    'current_customer_outstanding' => 0,
    'cancellation_event_id' => 1,
    'cancellation_date' => '2026-06-26',
    'customer_penalty_amount' => 53,
    'customer_penalty_currency' => 'AED',
    'supplier_penalty_currency' => 'PKR',
];
$receipt = [
    'booking_id' => 38,
    'branch_id' => 2,
    'booking_reference' => 'BK-000038',
    'lead_traveler_id' => 60,
    'business_source_name' => 'Abubakar AED sales',
    'customer_name' => 'Rizwan chacha',
    'movement_date' => '2026-06-26',
    'currency' => 'AED',
    'debit_amount' => 1180,
];
$refund = [
    'booking_id' => 38,
    'branch_id' => 2,
    'booking_reference' => 'BK-000038',
    'lead_traveler_id' => 60,
    'business_source_name' => 'Abubakar AED sales',
    'customer_name' => 'Rizwan chacha',
    'movement_date' => '2026-08-29',
    'currency' => 'AED',
    'customer_refund_amount' => 600,
    'supplier_refund_amount' => 0,
];

$assertBalance = static function (array $report, string $entry, float $expected): void {
    foreach ((array) ($report['rows'] ?? []) as $row) {
        if ((string) ($row['ledger_entry'] ?? '') !== $entry) {
            continue;
        }
        if (abs((float) ($row['raw_balance_amount'] ?? 999.0) - $expected) > 0.005) {
            throw new RuntimeException($entry . ' has incorrect customer running balance.');
        }
        return;
    }
    throw new RuntimeException($entry . ' is missing from the account ledger.');
};

$full = $service->buildReport([
    'positions' => [$position],
    'customerReceipts' => [$receipt],
    'refundMovements' => [$refund],
]);
$assertBalance($full, 'Cancellation invoice reduction', -600.0);
$assertBalance($full, 'Customer refund paid', 0.0);

$partial = $service->buildReport([
    'positions' => [$position],
    'customerReceipts' => [$receipt],
    'refundMovements' => [array_merge($refund, ['customer_refund_amount' => 300])],
]);
$assertBalance($partial, 'Customer refund paid', -300.0);

$withSupplierRecovery = $service->buildReport([
    'positions' => [$position],
    'customerReceipts' => [$receipt],
    'refundMovements' => [
        $refund,
        array_merge($refund, [
            'movement_date' => '2026-09-01',
            'customer_refund_amount' => 0,
            'supplier_refund_amount' => 653,
        ]),
    ],
]);
$assertBalance($withSupplierRecovery, 'Supplier refund received', 0.0);

echo 'Account ledger customer-refund running balance regression passed.' . PHP_EOL;
