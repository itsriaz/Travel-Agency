<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$serviceWorkspacePath = BASE_PATH . '/app/Services/ServiceWorkspaceService.php';
$serviceSource = is_file($serviceWorkspacePath) ? (string) file_get_contents($serviceWorkspacePath) : '';
$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Service refund combined-event correction regression' . PHP_EOL;

$check(
    'Penalty/refund correction re-fetches the latest refund event for each scope before reversing',
    str_contains($serviceSource, "foreach (['customer', 'supplier'] as \$refundScope) {")
        && str_contains($serviceSource, "\$refundEvent = \$this->latestRefundEventForScope(\$eventRepository, \$serviceId, \$refundScope);")
);

$check(
    'Penalty/refund correction no longer reverses using stale preloaded customer or supplier event variables',
    ! str_contains($serviceSource, "\$refundEvent = \$refundScope === 'customer' ? \$latestCustomerRefundEvent : \$latestSupplierRefundEvent;")
);

$check(
    'Correction still reposts refund values only after reversal pass completes',
    str_contains($serviceSource, "\$this->refundService(\$refundInput, \$actorUserId, \$accessibleBranchIds, true);")
);

$check(
    'Trusted refund corrections validate against released refund truth when transient remaining credit is stale',
    str_contains($serviceSource, '$customerRefundCorrectionCeiling = round(max(')
        && str_contains($serviceSource, "(float) (\$input['trusted_existing_customer_refund_amount'] ?? 0),")
        && str_contains($serviceSource, '$customerRefundValidationBalance = $trustedExistingRefundCorrection')
        && str_contains($serviceSource, 'if ($customerRefundAmount > $customerRefundValidationBalance + 0.005) {')
);

$check(
    'Penalty/refund correction passes the original posted refund amounts into the trusted repost payload',
    str_contains($serviceSource, "\$refundInput['trusted_existing_customer_refund_amount'] = \$oldCustomerRefundAmount;")
        && str_contains($serviceSource, "\$refundInput['trusted_existing_supplier_refund_amount'] = \$oldSupplierRefundAmount;")
);

if ($failures !== []) {
    echo PHP_EOL . 'Service refund combined-event correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Service refund combined-event correction regression passed.' . PHP_EOL;
