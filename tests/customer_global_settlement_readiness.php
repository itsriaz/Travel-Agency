<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$controller = is_file(BASE_PATH . '/app/Controllers/ReportsController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/ReportsController.php')
    : '';
$service = is_file(BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php')
    : '';
$repository = is_file(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    : '';
$reportView = is_file(BASE_PATH . '/app/Views/reports/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/index.php')
    : '';
$reportService = is_file(BASE_PATH . '/app/Services/ReportService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php')
    : '';
$workspaceOutput = is_file(BASE_PATH . '/app/Views/workspace/output.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/output.php')
    : '';
$advanceCorrectionMigration = is_file(BASE_PATH . '/database/migrations/20260701_000053_customer_advance_corrections.php')
    ? (string) file_get_contents(BASE_PATH . '/database/migrations/20260701_000053_customer_advance_corrections.php')
    : '';
$globalView = is_file(BASE_PATH . '/app/Views/reports/global_customer_settlement.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/global_customer_settlement.php')
    : '';
$workspaceStationView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceScript = is_file(BASE_PATH . '/public/assets/js/workspace.js')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js')
    : '';

$check(
    'Global customer settlement routes are registered',
    str_contains($publicIndex, "/customers/settlements/global")
        && str_contains($publicIndex, 'saveGlobalCustomerSettlement')
        && str_contains($publicIndex, "/customers/advances")
        && str_contains($publicIndex, "/customers/advances/refund")
        && str_contains($publicIndex, "/customers/advances/correct")
        && str_contains($publicIndex, "/customers/advances/refund/correct")
);
$check(
    'Receivable aging links to global customer payment',
    str_contains($reportView, 'Lumpsum Customer Payment')
        && str_contains($reportView, "/customers/settlements/global")
);
$check(
    'Customer dues finder links to lump-sum customer payment',
    str_contains($workspaceStationView, 'data-customer-dues-modal')
        && str_contains($workspaceStationView, 'Lump-Sum Customer Payment')
        && str_contains($workspaceStationView, "/customers/settlements/global")
);
$check(
    'Customer dues finder exposes separate customer advance popup',
    str_contains($workspaceStationView, 'data-customer-advance-open')
        && str_contains($workspaceStationView, 'data-customer-advance-modal')
        && str_contains($workspaceStationView, "/customers/advances")
        && str_contains($workspaceStationView, 'data-customer-advance-treasury-account')
);
$check(
    'Workspace payment panel can use customer advance on current invoice',
    str_contains($workspaceStationView, 'data-payment-advance-row')
        && str_contains($workspaceStationView, 'advance_receipt_id')
        && str_contains($workspaceStationView, 'advance_apply_amount')
        && str_contains($workspaceStationView, 'data-payment-advance-select')
        && str_contains($workspaceStationView, 'data-payment-advance-amount')
        && str_contains($workspaceStationView, 'Customer Advance')
        && str_contains($workspaceStationView, 'No advance available')
        && ! str_contains($workspaceStationView, 'data-payment-advance-row hidden')
);
$check(
    'Global customer payment view posts selected receivable rows',
    str_contains($globalView, 'global_customer_receivable_id[]')
        && str_contains($globalView, 'data-global-receivable-select-all')
        && str_contains($globalView, 'data-global-customer-settlement-form')
        && str_contains($globalView, 'treasury_account_id')
);
$check(
    'Lumpsum customer payment report uses the simplified user-facing name',
    str_contains($globalView, 'Lumpsum Customer Payment')
        && str_contains($globalView, 'Post Lumpsum Customer Payment')
        && ! str_contains($globalView, 'Global Customer Payment')
);
$check(
    'Global customer payment filters auto-load without a manual Load button',
    str_contains($globalView, 'data-global-customer-filter-form')
        && str_contains($globalView, "filterForm.submit()")
        && ! str_contains($globalView, '>Load</button>')
);
$check(
    'Global customer payment supports customer advances',
    str_contains($globalView, 'Apply Advance')
        && str_contains($globalView, 'advance_receipt_id')
        && ! str_contains($globalView, 'Record Advance')
        && ! str_contains($globalView, 'Refund Customer Advance')
);
$check(
    'Repository can load and lock global customer receivables',
    str_contains($repository, 'globalSettlementCustomerOptions')
        && str_contains($repository, 'globalSettlementCurrencies')
        && str_contains($repository, 'globalOpenReceivables')
        && str_contains($repository, 'openGlobalReceivablesForSettlement')
        && str_contains($repository, 'FOR UPDATE')
);
$customerOptionMethodStart = strpos($repository, 'public function globalSettlementCustomerOptions');
$customerOptionMethodEnd = strpos($repository, 'public function globalSettlementCurrencies', $customerOptionMethodStart !== false ? $customerOptionMethodStart : 0);
$customerOptionMethod = $customerOptionMethodStart !== false && $customerOptionMethodEnd !== false
    ? substr($repository, $customerOptionMethodStart, $customerOptionMethodEnd - $customerOptionMethodStart)
    : '';
$check(
    'Lumpsum customer dropdown includes only customers with open receivables',
    str_contains($customerOptionMethod, 'FROM customer_receivable_items cri')
        && str_contains($customerOptionMethod, 'cri.status IN ("open", "partially_paid")')
        && str_contains($customerOptionMethod, 'cri.outstanding_amount > 0')
        && ! str_contains($customerOptionMethod, 'cr.unallocated_amount > 0')
        && ! str_contains($customerOptionMethod, 'LEFT JOIN customer_receivable_items cri')
);
$check(
    'Service records one global customer receipt and allocates it',
    str_contains($service, 'recordGlobalCustomerPayment')
        && str_contains($service, "'booking_reference' => 'GLOBAL'")
        && str_contains($service, 'Global customer payment auto-allocation')
        && str_contains($service, 'postCustomerReceiptRecorded')
        && str_contains($service, 'postCustomerReceiptAllocation')
);
$check(
    'Service records, applies, and refunds customer advance credit',
    str_contains($service, 'recordCustomerAdvance')
        && str_contains($service, 'applyCustomerAdvance')
        && str_contains($service, 'refundCustomerAdvance')
        && str_contains($service, "'receipt_purpose' => 'customer_advance'")
        && str_contains($repository, 'availableCustomerAdvances')
        && str_contains($repository, 'refundCustomerAdvance')
);
$check(
    'Customer advance correction audit table is migrated',
    str_contains($advanceCorrectionMigration, 'customer_advance_corrections')
        && str_contains($advanceCorrectionMigration, 'advance_received')
        && str_contains($advanceCorrectionMigration, 'advance_returned')
        && str_contains($advanceCorrectionMigration, 'reversal_journal_entry_id')
        && str_contains($advanceCorrectionMigration, 'new_journal_entry_id')
);
$check(
    'Service corrects received and returned customer advances with journal reversals',
    str_contains($service, 'correctCustomerAdvance(')
        && str_contains($service, 'correctCustomerAdvanceRefund(')
        && str_contains($service, 'reverseJournalEntry')
        && str_contains($service, 'recordCustomerAdvanceCorrection')
        && str_contains($service, 'postCustomerAdvanceRefund')
);
$check(
    'Repository persists customer advance correction audit rows',
    str_contains($repository, 'correctCustomerAdvanceReceipt')
        && str_contains($repository, 'correctCustomerAdvanceRefund')
        && str_contains($repository, 'recordCustomerAdvanceCorrection')
        && str_contains($repository, 'customer_advance_corrections')
);
$check(
    'Customer advance ledger exposes edit controls and raw correction fields',
    str_contains($reportService, "'customer_advance_ledger'")
        && str_contains($reportService, "'key' => 'action'")
        && str_contains($reportService, 'raw_payment_method')
        && str_contains($reportService, 'raw_received_amount')
        && str_contains($reportView, 'data-advance-correction-open')
        && str_contains($reportView, 'data-advance-correction-modal')
        && str_contains($reportView, '/customers/advances/correct')
        && str_contains($reportView, '/customers/advances/refund/correct')
);

$check(
    'Customer advance ledger correction modal uses the available CSRF input helper',
    str_contains($reportView, '\\App\\Helpers\\Csrf::input()')
        && ! str_contains($reportView, '\\App\\Helpers\\Csrf::field()')
);
$check(
    'Receipt save applies selected customer advance without duplicate zero receipt',
    str_contains($service, 'applyCustomerAdvanceToCurrentInvoice')
        && str_contains($service, "'advance_applied' => \$advanceApplyResult")
        && str_contains($service, 'workspace.receipt.advance_apply_posted')
        && str_contains($service, '$skipReceiptCreation = true')
);
$check(
    'Receipt payment history labels customer advance applications clearly',
    str_contains($workspaceOutput, 'Advance Applied:')
        && str_contains($workspaceOutput, 'receiptPurpose')
        && str_contains($repository, 'receipt_purpose')
        && str_contains($service, "'receipt_purpose' => 'customer_advance'")
);
$check(
    'Workspace script loads and caps customer advance for invoice payment',
    str_contains($workspaceScript, 'refreshPaymentAdvanceControls')
        && str_contains($workspaceScript, 'customerAdvanceAvailableUrl')
        && str_contains($workspaceScript, 'syncPaymentAdvanceAmountCap(currentInvoiceDueInPaymentCurrency)')
        && str_contains($workspaceScript, 'usesCustomerAdvance')
        && str_contains($workspaceScript, 'advanceApplied')
);
$check(
    'Controller exposes global customer payment screen and save action',
    str_contains($controller, 'globalCustomerSettlement')
        && str_contains($controller, 'saveGlobalCustomerSettlement')
        && str_contains($controller, 'recordGlobalCustomerPayment')
        && str_contains($controller, 'saveCustomerAdvance')
        && str_contains($controller, 'refundCustomerAdvance')
);

if ($failures !== []) {
    echo PHP_EOL . 'Customer global settlement readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    return 1;
}

echo PHP_EOL . 'Customer global settlement readiness passed.' . PHP_EOL;
return 0;
