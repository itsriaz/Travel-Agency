<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$view = (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php');
$javascript = (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js');
$css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
$service = (string) file_get_contents(BASE_PATH . '/app/Services/ServiceWorkspaceService.php');
$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$check(
    'User-facing correction action is named Edit Refund',
    str_contains($view, '>Edit Refund</button>')
        && str_contains($view, 'id="service-penalty-refund-title">Edit Refund</strong>')
        && ! str_contains($view, '>Edit Penalty / Refund</button>')
);
$check(
    'Unposted customer refund is disabled and routed to Pay Refund',
    str_contains($view, 'Customer refund has not been paid yet.')
        && str_contains($view, 'data-open-refund-workflow="customer"')
        && str_contains($view, 'Pay Customer Refund')
        && str_contains($javascript, "preferredTab: 'refund'")
        && str_contains($javascript, 'customerRefundAmountField.disabled = !customerRefundPosted')
);
$check(
    'Unposted supplier refund is disabled and routed to Pay Refund',
    str_contains($view, 'Supplier refund has not been received yet.')
        && str_contains($view, 'data-open-refund-workflow="supplier"')
        && str_contains($view, 'Record Supplier Refund')
        && str_contains($javascript, 'supplierRefundAmountField.disabled = !supplierRefundPosted')
);
$check(
    'Existing refund correction restores its saved method and treasury account',
    str_contains($service, "latest_customer_refund_payment_method")
        && str_contains($service, "latest_supplier_refund_payment_method")
        && str_contains($javascript, 'latestCustomerRefundPaymentMethod')
        && str_contains($javascript, 'latestSupplierRefundPaymentMethod')
        && str_contains($javascript, 'preferredAccountId')
);
$check(
    'Correction endpoint cannot create a new customer or supplier refund',
    str_contains($service, "if (\$latestCustomerRefundEvent === null && \$customerRefundAmount > 0.005)")
        && str_contains($service, 'Customer refund has not been paid yet. Use Pay Customer Refund')
        && str_contains($service, "if (\$latestSupplierRefundEvent === null && \$supplierRefundAmount > 0.005)")
        && str_contains($service, 'Supplier refund has not been received yet. Use Record Supplier Refund')
);
$check(
    'Trusted correction bypass is internal and defaults to disabled',
    str_contains($service, 'bool $trustedExistingRefundCorrection = false')
        && str_contains($service, '! $trustedExistingRefundCorrection')
        && str_contains($service, '$this->refundService($refundInput, $actorUserId, $accessibleBranchIds, true);')
);
$check(
    'Status notices use a compact professional layout',
    str_contains($css, '.legacy-service-correction-bar .legacy-service-refund-status')
        && str_contains($css, '[data-correction-customer-refund-status]')
        && str_contains($css, '[data-correction-supplier-refund-status]')
);

if ($failures !== []) {
    echo PHP_EOL . 'Service refund correction guard regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Service refund correction guard regression passed.' . PHP_EOL;
return 0;
