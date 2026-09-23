<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
$controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/ControlController.php');
$view = (string) file_get_contents(BASE_PATH . '/app/Views/control/linked_party_settlements.php');
$service = (string) file_get_contents(BASE_PATH . '/app/Services/CounterpartyOffsetService.php');
$commercialSync = (string) file_get_contents(BASE_PATH . '/app/Services/CommercialObligationSyncService.php');
$manualFormStart = strpos($view, '<form method="post" action="<?= e(url(\'/linked-party-settlements/save\')) ?>" class="manual-offset-form"');
$manualFormEnd = $manualFormStart === false ? false : strpos($view, '</form>', $manualFormStart);
$manualForm = $manualFormStart === false || $manualFormEnd === false
    ? ''
    : substr($view, $manualFormStart, $manualFormEnd - $manualFormStart + strlen('</form>'));
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

echo 'Linked-party settlement administrator UI readiness' . PHP_EOL;

$check(
    'Settlement routes require financial-administrator middleware',
    substr_count($routes, '/linked-party-settlements') >= 3
        && substr_count($routes, 'FinancialAdminMiddleware::class') >= 3
);
$check(
    'Both settlement writes verify CSRF',
    str_contains($controller, 'saveLinkedPartySettlement')
        && str_contains($controller, 'voidLinkedPartySettlement')
        && substr_count($controller, 'Csrf::verify') >= 2
);
$check(
    'Screen offers itemized manual invoice amounts and history with audited reversal',
    str_contains($view, 'Manual Linked Settlements')
        && str_contains($view, 'Settlement History')
        && str_contains(strtolower($view), 'account-holder recovery balances')
        && str_contains($view, 'Reverse')
        && str_contains($view, 'name="account_allocations[')
        && str_contains($view, 'name="payable_allocations[')
        && str_contains($view, 'Both selected totals must be equal')
        && str_contains($view, 'id="manual-account-total"')
        && str_contains($view, 'id="manual-payable-total"')
        && str_contains($view, 'Confirm Manual Adjustment')
);
$check(
    'Manual adjustment form is non-cash and has no treasury fields',
    str_contains(strtolower($view), 'no cash or bank money moves')
        && ! str_contains($manualForm, 'treasury_account_id')
        && ! str_contains($manualForm, 'payment_method')
        && ! str_contains($manualForm, 'name="amount"')
);
$check(
    'Browser blocks confirmation until manually entered side totals match',
    str_contains($view, "sideTotal('account')")
        && str_contains($view, "sideTotal('payable')")
        && str_contains($view, 'manualSubmit.disabled = !ready')
);
$check(
    'Legacy automatic entry points remain compatible without an automatic posting path',
    str_contains($service, 'autoSettleForFinancialContext')
        && str_contains($service, 'autoSettleExisting')
        && ! str_contains($service, 'AUTO-OFFSET-')
);
$check(
    'Legacy booking synchronization call remains present but reaches a disabled compatibility entry point',
    str_contains($commercialSync, 'autoSettleForFinancialContext')
        && str_contains($commercialSync, 'business_source_id')
        && str_contains($service, '// Compatibility entry points: old callers must never settle balances automatically.')
);
$check(
    'Service posts AP-to-AR only and never creates receipt, supplier payment, or treasury movement',
    str_contains($service, "'AP_CONTROL'")
        && str_contains($service, "'AR_CONTROL'")
        && ! str_contains($service, 'createReceipt')
        && ! str_contains($service, 'createSupplierPayment')
        && ! str_contains($service, 'Treasury')
);

if ($failures !== []) {
    echo PHP_EOL . 'Linked-party settlement administrator UI readiness failed: ' . implode(', ', $failures) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Linked-party settlement administrator UI readiness passed.' . PHP_EOL;
