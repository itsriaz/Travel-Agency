<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$serviceWorkspacePath = BASE_PATH . '/app/Services/ServiceWorkspaceService.php';
$workspaceViewPath = BASE_PATH . '/app/Views/workspace/partials/station.php';
$workspaceControllerPath = BASE_PATH . '/app/Controllers/WorkspaceController.php';
$serviceSource = is_file($serviceWorkspacePath) ? (string) file_get_contents($serviceWorkspacePath) : '';
$viewSource = is_file($workspaceViewPath) ? (string) file_get_contents($workspaceViewPath) : '';
$controllerSource = is_file($workspaceControllerPath) ? (string) file_get_contents($workspaceControllerPath) : '';
$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Customer refund booking-currency regression' . PHP_EOL;

$check(
    'Customer refund amount is read directly in booking currency',
    str_contains($serviceSource, "\$customerRefundAmount = \$this->customerRefundAmountInBookingCurrency(\$input, 'customer_refund_amount');")
);
$check(
    'Customer refund save resolves the settlement truth currency from cancellation payload',
    str_contains($serviceSource, "\$customerRefundCurrency = \$this->resolveCustomerRefundTruthCurrency(\$cancelPayload, \$currency, (string) (\$cancelEvent['currency'] ?? ''));")
        && str_contains($serviceSource, "'currency' => \$customerRefundCurrency,")
);
$check(
    'Dedicated customer refund booking-currency helper exists',
    str_contains($serviceSource, 'private function customerRefundAmountInBookingCurrency(array $input, string $field): float')
        && str_contains($serviceSource, 'return $this->moneyValue($input[$field] ?? 0);')
);
$check(
    'Refund detail payload preserves booking currency separately from actual payout currency',
    str_contains($serviceSource, 'bool $forceBookingCurrency = false')
        && str_contains($serviceSource, "'booking_currency' => \$currency")
        && str_contains($serviceSource, "'refund_currency' => \$refundCurrency")
);
$check(
    'New cancellation settlement persists converted customer refund credit in invoice currency',
    str_contains($serviceSource, "'available_customer_refund_credit_currency' => \$currency")
        && str_contains($serviceSource, "'target_customer_refund_credit_currency' => \$currency")
        && str_contains($serviceSource, "'customer_refund_basis_currency' => \$currency")
);
$check(
    'Receipt-backed refunds follow their remaining receipt credit without erasing historical entitlement',
    str_contains($serviceSource, 'releasedReceiptUnallocatedCreditTotal($bookingReference, $currency, $releasedReceiptIds)')
        && str_contains($serviceSource, 'bookingHasReceiptEvidence($bookingReference, $currency)')
        && str_contains($serviceSource, '$serviceRemainingCustomerRefundCredit = $hasReceiptEvidence')
        && str_contains($serviceSource, "'current_booking_credit_cap_applied' => \$hasReceiptEvidence || ! \$hasModernAvailableCredit")
);
$check(
    'Legacy mixed-currency settlements derive refund truth from source-currency amounts before raw fallbacks',
    str_contains($serviceSource, 'private function resolveModernCustomerRefundTruthAmount(array $cancelPayload, string $currency): float')
        && str_contains($serviceSource, '$derivedTargetAmount = $this->deriveModernCustomerRefundTruthAmount($cancelPayload, $currency);')
        && str_contains($serviceSource, 'private function deriveModernCustomerRefundTruthAmount(array $cancelPayload, string $currency): float')
        && str_contains($serviceSource, '$expectedSupplierRefundSourceAmount - $customerPenaltySourceAmount + $agencyFeeRefundAmount')
);
$check(
    'Service refund can consume synthetic settlement-backed customer credit when no receipt rows exist in the refund truth currency',
    str_contains($serviceSource, '$allowSyntheticSettlementCredit = $customerRefundAmount > 0.005')
        && str_contains($serviceSource, '$customerReceiptCreditConsumptionAmount <= 0.005')
        && str_contains($serviceSource, "(float) (\$customerRefundState['service_remaining_customer_refund_credit'] ?? 0) > 0.005")
        && str_contains($serviceSource, '$customerPaymentRepository->applyRefundAgainstBookingReceiptCredit(')
        && str_contains($serviceSource, '$allowSyntheticSettlementCredit')
);
$check(
    'Customer refund truth currency resolver exists',
    str_contains($serviceSource, "private function resolveCustomerRefundTruthCurrency(array \$cancelPayload, string \$fallbackCurrency, string \$eventCurrency = ''): string")
        && str_contains($serviceSource, "'available_customer_refund_credit_currency'")
        && str_contains($serviceSource, "'target_customer_refund_credit_currency'")
        && str_contains($serviceSource, "'settlement_currency'")
);
$paymentRepositoryPath = BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php';
$paymentRepositorySource = is_file($paymentRepositoryPath) ? (string) file_get_contents($paymentRepositoryPath) : '';
$check(
    'Customer payment repository supports synthetic settlement-backed refund application and restoration',
    str_contains($paymentRepositorySource, 'bool $allowSyntheticSettlementCredit = false')
        && str_contains($paymentRepositorySource, 'customer.receipt.refund_applied_synthetic')
        && str_contains($paymentRepositorySource, 'customer.receipt.refund_restored_synthetic')
);
$check(
    'Customer refund detail uses actual payout currency without forcing invoice currency',
    str_contains($serviceSource, "if (\$requireCustomerBankDestination) {")
        && str_contains($serviceSource, '$refundExchangeRateToBooking = round($refundSourceAmount / $refundAmount, 8);')
        && str_contains($serviceSource, 'postCrossCurrencyCustomerRefund($customerJournalData)')
);
$check(
    'Main refund form takes exact payout and selected currency',
    str_contains($viewSource, 'name="customer_refund_source_amount" value="" min="1"')
        && str_contains($viewSource, 'name="customer_refund_currency" data-refund-fx-currency-trigger="customer"')
        && str_contains($viewSource, 'name="customer_refund_amount" value="0" disabled')
);
$check(
    'Edit Refund loads saved actual amount and offers all supported payout currencies',
    str_contains($viewSource, "\$latestCustomerRefundDetail['refund_source_amount'] ?? \$latestCustomerRefundAmountOnlyValue")
        && str_contains($viewSource, "\$latestCustomerRefundDetail['refund_currency'] ?? \$activeService['customerRefundTruthCurrency']")
        && str_contains($viewSource, '<select name="customer_refund_currency" aria-label="Actual customer refund currency"')
);
$check(
    'Workspace controller exposes customer refund truth currency to the frontend',
    str_contains($controllerSource, "'customerRefundTruthCurrency' => (string) (\$serviceRow['customer_refund_truth_currency'] ?? \$invoiceCurrency),")
);
$workspaceScriptPath = BASE_PATH . '/public/assets/js/workspace.js';
$workspaceScript = is_file($workspaceScriptPath) ? (string) file_get_contents($workspaceScriptPath) : '';
$check(
    'Customer payout UI uses saved credit currency rather than assuming invoice currency',
    str_contains($workspaceScript, "(role === 'customer' ? activeServiceLine?.customerRefundTruthCurrency : '')")
        && str_contains($workspaceScript, "currentActiveServiceLineForRefund()?.customerRefundTruthCurrency || refundFxBookingCurrencyCode()")
        && str_contains($workspaceScript, "customerRefundCurrency !== refundFxAccountingCurrencyCode('customer')")
);
$check(
    'Customer treasury choices match payout currency without narrowing supplier choices',
    str_contains($workspaceScript, 'restrictDimension = false')
        && substr_count($workspaceScript, 'restrictDimension: true,') === 2
);

if ($failures !== []) {
    echo PHP_EOL . 'Customer refund booking-currency regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Customer refund booking-currency regression passed.' . PHP_EOL;
