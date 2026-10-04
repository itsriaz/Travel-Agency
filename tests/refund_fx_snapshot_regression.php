<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$workspace = new \App\Services\ServiceWorkspaceService($app);
$method = new ReflectionMethod($workspace, 'normalizedRefundDetailPayload');
$bookingAmountMethod = new ReflectionMethod($workspace, 'refundAmountInBookingCurrency');
$failures = [];
$check = static function (string $name, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (! $passed) $failures[] = $name;
};

foreach (['customer', 'supplier'] as $role) {
    $input = [
        $role . '_refund_amount' => 50242, $role . '_refund_source_amount' => 50242,
        $role . '_refund_currency' => 'PKR', $role . '_refund_exchange_rate' => round(50242 / 653, 8),
        $role . '_refund_rate_date' => '2026-09-25', 'refund_event_date' => '2026-09-26',
    ];
    $normalize = static fn (array $values): array => $method->invoke(
        $workspace, $values, 'supplier_credit', 2, 'AED', 653,
        $role === 'customer' ? 'refund_treasury_account_id' : 'supplier_refund_treasury_account_id', $role === 'customer'
    );
    $detail = $normalize($input);
    $check($role . ' snapshot preserves original amount, currencies, date, direction and equivalent',
        $detail['refund_currency'] === 'PKR' && $detail['booking_currency'] === 'AED'
        && $detail['refund_source_amount'] === 50242.0 && $detail['refund_booking_equivalent'] === 653.0
        && $detail['refund_rate_date'] === '2026-09-25'
        && $detail['refund_exchange_rate_direction'] === 'booking_to_refund'
        && abs($detail['refund_exchange_rate_to_booking'] - 50242 / 653) < 0.00000001);
    $input[$role . '_refund_exchange_rate'] = '';
    try {
        $normalize($input);
        $check($role . ' missing cross-currency rate rejects, not inferred silently', false);
    } catch (RuntimeException $exception) {
        $check($role . ' missing cross-currency rate rejects, not inferred silently', str_contains($exception->getMessage(), 'exchange rate'));
    }
    $input[$role . '_refund_currency'] = 'AED';
    $input[$role . '_refund_amount'] = 653;
    $input[$role . '_refund_exchange_rate'] = '0';
    $same = $normalize($input);
    $check($role . ' same-currency refund needs no submitted FX and uses internal identity only',
        $same['refund_exchange_rate_to_booking'] === 1.0 && $same['refund_rate_date'] === null
        && $same['refund_source_amount'] === 653.0);
}

$supplierSource = [
    'supplier_refund_amount' => 653,
    'supplier_refund_amount_basis' => 'booking_equivalent',
    'supplier_refund_currency' => 'PKR',
    'supplier_refund_source_amount' => 50242,
    'supplier_refund_exchange_rate' => round(50242 / 653, 8),
    'supplier_refund_rate_date' => '2026-10-02',
];
$equivalent = $bookingAmountMethod->invoke($workspace, $supplierSource, 'supplier_refund_amount', 'AED');
$supplierDetail = $method->invoke($workspace, $supplierSource, 'supplier_credit', 2, 'AED', $equivalent,
    'supplier_refund_treasury_account_id', false);
$check('Supplier source PKR 50,242 and AED 653 equivalent stay distinct',
    $equivalent === 653.0 && $supplierDetail['refund_source_amount'] === 50242.0
    && $supplierDetail['refund_currency'] === 'PKR'
    && $supplierDetail['refund_booking_equivalent'] === 653.0);
$badSupplierSource = array_replace($supplierSource, ['supplier_refund_source_amount' => 653]);
try {
    $bookingAmountMethod->invoke($workspace, $badSupplierSource, 'supplier_refund_amount', 'AED');
    $check('Mismatched supplier source and AED equivalent are rejected', false);
} catch (RuntimeException $exception) {
    $check('Mismatched supplier source and AED equivalent are rejected',
        str_contains($exception->getMessage(), 'do not match'));
}

echo 'No financial records written; normalization checks only.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
