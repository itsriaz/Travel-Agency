<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$workspaceController = is_file(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    : '';
$paymentFoundation = is_file(BASE_PATH . '/app/Services/CustomerPaymentFoundationService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/CustomerPaymentFoundationService.php')
    : '';
$workspaceView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceScript = is_file(BASE_PATH . '/public/assets/js/workspace.js')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js')
    : '';

$check('Workspace treasury account refresh route is registered', str_contains($publicIndex, "/workspace/payments/treasury-accounts"));
$check('Workspace controller exposes treasury account refresh action', str_contains($workspaceController, 'function paymentTreasuryAccounts'));
$check('Workspace view exposes treasury account refresh URL', str_contains($workspaceView, 'data-payment-treasury-accounts-url'));
$check('Payment foundation loads treasury accounts across accessible branches', str_contains($paymentFoundation, '->accounts($treasuryBranchIds)'));
$check('Workspace script can fetch refreshed treasury accounts', str_contains($workspaceScript, 'const refreshPaymentTreasuryAccountsFromServer = async (options = {}) =>'));
$check('Workspace script refreshes treasury accounts when focus returns', str_contains($workspaceScript, "window.addEventListener('focus', handleTreasurySetupReturn);"));
$check('Workspace script refreshes treasury accounts on visibility change', str_contains($workspaceScript, "document.addEventListener('visibilitychange', () => {"));
$check('Workspace script preserves manually selected payment currency after treasury return', str_contains($workspaceScript, 'markManualPaymentCurrencySelection(') && str_contains($workspaceScript, 'const restoredPaymentCurrency = String(payload?.payment?.receipt_currency?.value || \'\').trim().toUpperCase();'));

if ($failures !== []) {
    echo PHP_EOL . 'Workspace treasury refresh readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Workspace treasury refresh readiness passed.' . PHP_EOL;
