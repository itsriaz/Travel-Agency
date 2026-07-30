<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\ReportService($app);
$reportMethod = new ReflectionMethod($service, 'customerOutstandingReport');

$source = static function (string $currency, float $outstanding, int $bookingId): array {
    return [
        'booking_id' => $bookingId,
        'booking_reference' => sprintf('BK-%06d', $bookingId),
        'booking_date' => '2026-07-28',
        'branch_name' => 'Test Branch',
        'business_source_name' => 'Test Account',
        'lead_traveler_name' => 'Test Customer',
        'lead_traveler_id' => 1,
        'service_type' => 'air_ticket',
        'service_line_reference' => 'SV-001',
        'currency' => $currency,
        'total_due' => $outstanding,
        'total_allocated' => 0.0,
        'total_outstanding' => $outstanding,
    ];
};

[, $summaryCards] = $reportMethod->invoke(
    $service,
    [
        $source('PKR', 12000.00, 1),
        $source('AED', 850.00, 2),
    ],
    ['PKR' => 1.0, 'AED' => 77.0]
);

$labels = array_column($summaryCards, 'label');
$values = array_column($summaryCards, 'value', 'label');
$failures = [];

if ($labels !== ['Outstanding / PKR', 'Outstanding / AED']) {
    $failures[] = 'Native-currency cards are missing or in the wrong order.';
}
if (($values['Outstanding / PKR'] ?? '') !== 'PKR 12,000.00') {
    $failures[] = 'PKR outstanding total is incorrect.';
}
if (($values['Outstanding / AED'] ?? '') !== 'AED 850.00') {
    $failures[] = 'AED outstanding total is incorrect.';
}
if (array_filter(
    $summaryCards,
    static fn (array $card): bool => str_contains((string) ($card['label'] ?? ''), 'Consolidated')
) !== []) {
    $failures[] = 'A consolidated summary card is still present.';
}

if ($failures !== []) {
    fwrite(STDERR, "Customer Outstanding native-currency summary regression failed:\n - "
        . implode("\n - ", $failures)
        . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer Outstanding shows separate PKR and AED totals without consolidation.' . PHP_EOL;
