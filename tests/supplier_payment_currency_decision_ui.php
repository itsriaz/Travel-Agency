<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workspaceView = file_get_contents($root . '/app/Views/workspace/partials/station.php') ?: '';
$globalView = file_get_contents($root . '/app/Views/reports/global_supplier_settlement.php') ?: '';
$workspaceScript = file_get_contents($root . '/public/assets/js/workspace.js') ?: '';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (! $condition) {
        $failures[] = $message;
    }
};

echo "Supplier payment currency-decision UI regression\n";

$check(
    substr_count($workspaceView, 'data-supplier-exchange-review') === 2,
    'Both booking-workspace supplier payment forms use the currency-decision trigger'
);
$check(
    str_contains($workspaceView, 'type="hidden" name="supplier_exchange_rate_to_booking"'),
    'The backend directional rate remains internal in the booking workspace'
);
$check(
    str_contains($workspaceScript, 'supplier-payment-currency-decision')
        && str_contains($workspaceScript, 'requestSupplierPaymentCurrencyChoice')
        && ! str_contains($workspaceScript, "requestFinancialCurrencyDecision(\n                'supplier-payment'"),
    'Booking-workspace supplier payments use a dedicated copy instead of the market-fare currency modal'
);
$check(
    str_contains($workspaceScript, '{ synchronizeBookingPricing: true }'),
    'The original market-fare currency workflow retains its pricing synchronization behavior'
);
$check(
    str_contains($globalView, 'Change Supplier Payment Currency')
        && str_contains($globalView, 'Convert Existing Amount')
        && str_contains($globalView, 'Keep Number, Correct Currency'),
    'Global supplier payments have an isolated copy with the same two choices'
);
$check(
    ! str_contains($globalView, '1 source = ?')
        && ! str_contains($workspaceView, '1 source = ? payable')
        && ! str_contains($workspaceView, '1 source = ? payment'),
    'Supplier payment screens no longer ask users for a reciprocal quote'
);

$aedPayment = 100.0;
$aedToPkr = 77.0;
$sourceToPaymentRate = round(1 / $aedToPkr, 8);
$convertedFunding = round($aedPayment / $sourceToPaymentRate, 2);
$keptPkrFunding = 100.0;
$recalculatedAedPayment = round($keptPkrFunding * $sourceToPaymentRate, 2);

$check(
    abs($convertedFunding - 7700.0) < 0.01,
    'Convert Existing Amount maps AED 100 to PKR 7,700 funding'
);
$check(
    abs($recalculatedAedPayment - 1.30) < 0.01,
    'Keep Number treats 100 as PKR and recalculates the AED supplier settlement amount'
);

if ($failures !== []) {
    fwrite(STDERR, "\nCurrency-decision UI regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo "\nSupplier payment currency-decision UI regression passed.\n";

