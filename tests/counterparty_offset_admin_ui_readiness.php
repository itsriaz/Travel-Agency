<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
$controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/ControlController.php');
$view = (string) file_get_contents(BASE_PATH . '/app/Views/control/linked_party_settlements.php');
$service = (string) file_get_contents(BASE_PATH . '/app/Services/CounterpartyOffsetService.php');
$commercialSync = (string) file_get_contents(BASE_PATH . '/app/Services/CommercialObligationSyncService.php');
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
    'Screen is a compact automatic settlement history with audited reversal',
    str_contains($view, 'Automatic Linked Settlements')
        && str_contains($view, 'Settlement History')
        && str_contains($view, 'account-holder recovery balances')
        && str_contains($view, 'Reverse')
        && ! str_contains($view, 'Settle Balance')
        && ! str_contains($view, 'Save Settlement')
);
$check(
    'Screen identifies the action as non-cash and does not request a treasury account',
    str_contains(strtolower($view), 'moving cash or bank money')
        && ! str_contains($view, 'treasury_account_id')
        && ! str_contains($view, 'payment_method')
);
$check(
    'Automatic settlement service supports transaction-time and existing-balance reconciliation',
    str_contains($service, 'autoSettleForFinancialContext')
        && str_contains($service, 'autoSettleExisting')
        && str_contains($service, 'AUTO-OFFSET-')
);
$check(
    'Booking financial synchronization invokes automatic linked-party settlement',
    str_contains($commercialSync, 'autoSettleForFinancialContext')
        && str_contains($commercialSync, 'business_source_id')
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
