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
    str_contains($serviceSource, "\$customerRefundCurrency = \$this->resolveCustomerRefundTruthCurrency(\$cancelPayload, \$currency);")
        && str_contains($serviceSource, "'currency' => \$customerRefundCurrency,")
);
$check(
    'Dedicated customer refund booking-currency helper exists',
    str_contains($serviceSource, 'private function customerRefundAmountInBookingCurrency(array $input, string $field): float')
        && str_contains($serviceSource, 'return $this->moneyValue($input[$field] ?? 0);')
);
$check(
    'Refund detail payload supports forcing booking currency',
    str_contains($serviceSource, 'bool $forceBookingCurrency = false')
        && str_contains($serviceSource, 'if ($forceBookingCurrency) {')
        && str_contains($serviceSource, '$refundCurrency = $currency;')
        && str_contains($serviceSource, '$refundExchangeRateToBooking = $refundAmount > 0.005 ? 1.0 : null;')
);
$check(
    'Cancellation settlement persists customer refund credit in a dedicated truth currency',
    str_contains($serviceSource, "'available_customer_refund_credit_currency' => \$settlementCurrency")
        && str_contains($serviceSource, "'target_customer_refund_credit_currency' => \$settlementCurrency")
        && str_contains($serviceSource, "'customer_refund_basis_currency' => \$settlementCurrency")
);
$check(
    'Modern customer refund state no longer caps refund truth by the old booking credit header currency',
    str_contains($serviceSource, '$remainingCustomerRefundCredit = $hasModernAvailableCredit')
        && str_contains($serviceSource, '? $unsettledReleasedCredit')
        && str_contains($serviceSource, "'current_booking_credit_cap_applied' => ! \$hasModernAvailableCredit")
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
    str_contains($serviceSource, 'private function resolveCustomerRefundTruthCurrency(array $cancelPayload, string $fallbackCurrency): string')
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
    'Customer refund detail payload is forced back to booking currency',
    str_contains(
        $serviceSource,
        "'refund_treasury_account_id',\n            true,\n            null,\n            null,\n            null,\n            true"
    )
);
$check(
    'Customer refund workflow only offers the settled due currency in the main workspace form',
    str_contains($viewSource, 'Customer refund is paid in the settled due currency.')
        && str_contains($viewSource, '<input type="hidden" name="customer_refund_currency" value="<?= e($customerRefundFixedCurrency) ?>">')
        && str_contains($viewSource, '<span class="legacy-service-event-money-currency" aria-hidden="true"><?= e($customerRefundFixedCurrency) ?></span>')
);
$check(
    'Customer refund correction form is locked to booking currency with rate 1',
    str_contains($viewSource, 'name="customer_refund_source_amount" value="<?= e(number_format(round((float) ($latestCustomerRefundAmountOnlyValue), 0), 0, \'.\', \'\')) ?>"')
        && str_contains($viewSource, 'name="customer_refund_exchange_rate" value="1"')
        && str_contains($viewSource, 'readonly')
);
$check(
    'Workspace controller exposes customer refund truth currency to the frontend',
    str_contains($controllerSource, "'customerRefundTruthCurrency' => (string) (\$serviceRow['customer_refund_truth_currency'] ?? \$invoiceCurrency),")
);

if ($failures !== []) {
    echo PHP_EOL . 'Customer refund booking-currency regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Customer refund booking-currency regression passed.' . PHP_EOL;
