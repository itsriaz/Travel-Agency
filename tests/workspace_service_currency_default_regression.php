<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$workspaceScript = BASE_PATH . '/public/assets/js/workspace.js';
$source = is_file($workspaceScript) ? (string) file_get_contents($workspaceScript) : '';
$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Workspace service-amount currency default regression' . PHP_EOL;

$helperMatch = [];
$helperFound = preg_match(
    '/const syncInvoiceAndPaymentCurrenciesFromServiceAmount = \(serviceAmountCurrency\) => \{(?<body>.*?)\n    \};/s',
    $source,
    $helperMatch
) === 1;
$helperBody = (string) ($helperMatch['body'] ?? '');

$check('Service-currency synchronization helper exists', $helperFound);
$check(
    'Service amount currency becomes the invoice default',
    str_contains($helperBody, 'serviceFields.currency.value = normalizedCurrency;')
        && str_contains($helperBody, 'paymentCurrentInvoiceInput.dataset.paymentCurrency = normalizedCurrency;')
);
$check(
    'Service amount currency becomes the payment default',
    str_contains($helperBody, 'paymentCurrencySelect.value = normalizedCurrency;')
        && str_contains($helperBody, "paymentCurrencySelect.dataset.paymentDefaultSource = 'service-amount';")
);
$check(
    'Automatic default clears stale payment overrides before applying the new service currency',
    str_contains($helperBody, 'delete paymentCurrencySelect.dataset.paymentManualSelection;')
        && str_contains($helperBody, 'delete paymentCurrencySelect.dataset.paymentManualContext;')
);
$check(
    'Changing the service amount currency invokes the shared default synchronization',
    str_contains(
        $source,
        "if (serviceChargeCurrencyChanged) {\n                    syncInvoiceAndPaymentCurrenciesFromServiceAmount(target.value);"
    )
);

$invoiceBranchMatch = [];
$invoiceBranchFound = preg_match(
    '/else if \(invoiceCurrencyChanged\) \{(?<body>.*?)\n                \}/s',
    $source,
    $invoiceBranchMatch
) === 1;
$invoiceBranchBody = (string) ($invoiceBranchMatch['body'] ?? '');
$check(
    'A later invoice-currency override does not overwrite payment currency',
    $invoiceBranchFound
        && ! str_contains($invoiceBranchBody, 'paymentCurrencySelect.value =')
        && ! str_contains($invoiceBranchBody, 'markManualPaymentCurrencySelection(')
);
$check(
    'A later payment-currency override remains explicitly supported',
    str_contains(
        $source,
        'markManualPaymentCurrencySelection(paymentCurrencySelect.value, currentInvoiceSnapshot().invoiceCurrency || \'PKR\');'
    )
        && str_contains($source, "paymentCurrencySelect.dataset.paymentManualSelection = '1';")
);
$check(
    'Cross-currency invoice overrides still open settlement handling',
    str_contains($source, 'invoiceCurrencyChanged')
        && str_contains($source, 'isCurrentInvoiceCrossCurrencySelection(paymentCurrencySelect?.value || \'\')')
        && str_contains($source, 'await maybeOpenExchangeSettlementModal({ focusIfEmpty: true });')
);
$check(
    'Reissue startup uses a hoisted exchange-rate resolver',
    str_contains(
        $source,
        'function resolvePricingExchangeRateFromMap(fromCurrency, toCurrency, effectiveDate = \'\')'
    )
        && ! str_contains(
            $source,
            'const resolvePricingExchangeRateFromMap = (fromCurrency, toCurrency, effectiveDate = \'\') =>'
        )
);
$check(
    'Confirmed direct or inverse pricing rates are reused before opening another prompt',
    str_contains($source, 'const applyAvailablePricingExchangeRates = (requirements)')
        && str_contains($source, 'const unresolvedRequirements = applyAvailablePricingExchangeRates(requirements);')
        && str_contains($source, 'if (unresolvedRequirements.length === 0 || everySnapshotValid)')
        && str_contains($source, 'return openPricingExchangeModal(unresolvedRequirements, signature);')
);
$check(
    'Branch currency conversion reuses a confirmed rate instead of prompting again',
    str_contains($source, 'const reusableBranchRate = resolvePricingExchangeRateFromMap(')
        && str_contains($source, 'if (reusableBranchRate > 0.005)')
        && str_contains($source, 'component.applyConvertedAmount(reusableBranchRate);')
);
$check(
    'Missing mixed-currency rates cannot silently turn supplier cost into zero invoice value',
    str_contains($source, "finalSalePriceInput.dataset.pricingRateMissing = '1';")
        && str_contains($source, "finalSalePriceInput.setCustomValidity('Confirm the exchange rate before saving this invoice.');")
        && str_contains($source, "serviceProfit.textContent = 'Exchange rate required';")
);
$check(
    'Service-charge percentages use supplier cost converted into the service currency',
    str_contains($source, 'const convertAmountBetweenPricingCurrencies = (amount, fromCurrency, toCurrency)')
        && str_contains(
            $source,
            "return convertAmountBetweenPricingCurrencies(\n                payable,\n                currentCostCurrencyCode(),\n                currentServiceChargeCurrencyCode()"
        )
        && str_contains($source, 'refreshServiceChargePercentAfterExchangeRate();')
);

if ($failures !== []) {
    echo PHP_EOL . 'Workspace service-currency default regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Workspace service-currency default regression passed.' . PHP_EOL;
