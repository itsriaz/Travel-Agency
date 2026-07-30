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

foreach (['cancel', 'settlement', 'refund', 'reissue', 'edit'] as $tab) {
    $check(
        'Service workflow exposes the ' . $tab . ' tab and panel',
        str_contains($view, 'data-service-workflow-tab="' . $tab . '"')
            && str_contains($view, 'data-service-workflow-panel="' . $tab . '"')
    );
}

$check(
    'Tabbed UI preserves every dedicated financial endpoint',
    str_contains($view, "url('/workspace/services/cancel')")
        && str_contains($view, "url('/workspace/services/cancellation-financials')")
        && str_contains($view, "url('/workspace/services/refund')")
        && str_contains($view, "url('/workspace/services/reissue')")
        && str_contains($view, "url('/workspace/services/financial-correction')")
);
$check(
    'Tabs derive availability from existing service-state panels',
    str_contains($javascript, 'syncServiceWorkflowTabs')
        && str_contains($javascript, "String(panel.dataset.serviceWorkflowPanel || '') === key && !panel.hidden")
        && str_contains($javascript, "button.disabled = !available")
        && str_contains($javascript, "panel.classList.toggle('service-workflow-panel--tab-hidden'")
);
$check(
    'Reissue remains explicitly independent from cancellation',
    str_contains($view, 'Independent ticket exchange')
        && str_contains($javascript, 'Exchange the selected air ticket without cancelling it')
);
$check(
    'Reissue pricing uses independently denominated supplier charge and agency fee with an automatic customer total',
    str_contains($view, 'Supplier Charge')
        && str_contains($view, 'Agency Service Fee')
        && str_contains($view, 'Customer Pays')
        && str_contains($view, 'name="reissue_supplier_currency"')
        && str_contains($view, 'name="reissue_agency_fee_currency"')
        && str_contains($view, 'name="reissue_customer_currency"')
        && str_contains($view, 'name="reissue_received_currency"')
        && ! str_contains($view, '<span>Customer Extra</span>')
        && str_contains($view, 'name="reissue_pricing_mode" value="supplier_plus_service"')
        && str_contains($javascript, 'syncServiceReissueCustomerTotal')
        && str_contains($service, "'supplier_plus_service'")
);
$check(
    'Refund remains disabled until cancellation settlement on both UI and server',
    str_contains($view, 'Available after settlement')
        && str_contains($view, '&& $settlementFinanciallySettled')
        && str_contains($javascript, '&& settlementFinanciallySettled')
        && str_contains($service, 'Settle the service cancellation before posting a customer or supplier refund.')
);
$check(
    'Cancellation settlement supports zero, partial, or full agency fee refund with live customer refund calculation',
    substr_count($view, 'name="agency_fee_refund_amount"') >= 2
        && str_contains($view, 'data-settlement-customer-refund-due')
        && str_contains($javascript, 'liveExpectedSupplierRefundAmount - liveCustomerPenaltyAmount + liveAgencyFeeRefundAmount')
        && str_contains($service, 'Agency fee refund cannot exceed the agency service fee charged on this service.')
        && str_contains($service, '$expectedSupplierRefundAmount - $customerPenaltyAmount + $agencyFeeRefundAmount')
);
$check(
    'Cash customer refunds can use a bank funding source without requesting customer bank details',
    str_contains($javascript, 'allowBankSourceForCash')
        && str_contains($javascript, "method === 'cash' && allowBankSourceForCash")
        && str_contains($service, '$requireCustomerBankDestination && $paymentMethod === \'cash\'')
);
$check(
    'Zero customer payout and supplier-account credit do not require a treasury account',
    str_contains($service, '$refundAmount > 0.005 && in_array($paymentMethod')
        && str_contains($service, "if (\$method === 'supplier_credit')")
);
$check(
    'Refund entry distinguishes actual customer payment from retained transferable credit',
    str_contains($view, 'Paid to Customer Now')
        && str_contains($view, 'Keep as Available Credit')
        && str_contains($view, 'Pay Customer Now')
        && ! str_contains($view, 'customer_refund_payment_confirmed')
        && substr_count($view, 'data-customer-refund-pay-now-field') >= 3
        && str_contains($view, 'aria-label="Amount actually paid to customer now"')
        && str_contains($javascript, 'syncCustomerRefundTreatment')
        && str_contains($javascript, 'serviceCustomerRefundPayNowFields')
        && str_contains($javascript, "classList.toggle('legacy-service-event-bar--retain-credit', !payNow)")
        && str_contains($css, '.legacy-service-event-bar--retain-credit .legacy-service-event-bar__row--refund-customer')
        && str_contains($css, 'grid-template-columns: 150px minmax(420px, 1fr);')
        && str_contains($view, '<legend>Customer Refund</legend>')
        && ! str_contains($view, 'Customer Refund Treatment')
        && str_contains($javascript, "? 'Save'")
        && str_contains($javascript, 'Customer refund remains available credit. No money was paid out.')
        && str_contains($service, 'Select Pay Customer Now before posting money as returned to the customer.')
);
$check(
    'Supplier retained-credit method uses the client-facing supplier-account label',
    str_contains($view, "'supplier_credit' => 'Refund to Supplier Account'")
        && ! str_contains($view, "'supplier_credit' => 'Retained as Supplier Credit'")
);
$check(
    'Cancellation workflow uses the requested save-refund and pay-refund labels',
    substr_count($view, '<strong>Save Refund</strong>') === 1
        && str_contains($view, '<strong>Pay Refund</strong>')
        && substr_count($view, '>Save Refund</button>') >= 2
        && str_contains($javascript, ": 'Save Refund';")
        && str_contains($css, 'content: "Save Refund";')
        && str_contains($css, 'content: "Pay Refund";')
);
$check(
    'Completed refunds close the booking-action modal instead of advancing to reissue',
    preg_match(
        '/workflowStep === \'refunded\'[\s\S]*?closeServiceEditBookingModal\(\);[\s\S]*?openLatestRefundReceipt\(\)/',
        $javascript
    ) === 1
);
$check(
    'Reopen cancellation form gives the reason field priority and keeps its action compact',
    str_contains($view, 'legacy-service-event-field--reopen-reason')
        && str_contains($view, 'legacy-service-event-action--reopen')
        && str_contains($css, '.legacy-service-event-bar--cancel-reopen')
        && str_contains($css, 'grid-template-columns: minmax(360px, 1fr) auto;')
        && str_contains($css, 'min-width: 150px;')
);
$check(
    'Professional responsive workflow styling is present',
    str_contains($css, '.service-workflow-tabs')
        && str_contains($css, '.service-workflow-tab.is-active')
        && str_contains($css, '.service-workflow-panel--tab-hidden')
        && str_contains($css, '@keyframes service-workflow-panel-in')
);
$check(
    'Workflow forms match the tabs with consistent professional field and panel sizing',
    str_contains($css, '[data-service-edit-booking-modal] [data-service-workflow-panel] {')
        && str_contains($css, 'min-height: 94px;')
        && str_contains($css, '[data-service-edit-booking-modal] [data-service-event-bar="cancel"]')
        && str_contains($css, 'grid-template-columns: 150px minmax(320px, .9fr) minmax(360px, 1.1fr) 170px;')
        && str_contains($css, '[data-service-edit-booking-modal] [data-service-workflow-panel] input,')
        && str_contains($css, 'min-height: 34px;')
);

if ($failures !== []) {
    echo PHP_EOL . 'Service workflow tabs readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Service workflow tabs readiness passed.' . PHP_EOL;
return 0;
