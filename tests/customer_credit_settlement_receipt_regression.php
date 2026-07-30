<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$bookingStatement = $db->prepare(
    'SELECT id, branch_id
     FROM bookings
     WHERE booking_reference = "BK-000277"
     LIMIT 1'
);
$bookingStatement->execute();
$booking = $bookingStatement->fetch() ?: [];

if ((int) ($booking['id'] ?? 0) <= 0) {
    echo '[SKIP] BK-000277 is not present in this database.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $creditReceiptStatement = $db->query(
        'SELECT id
         FROM customer_receipts
         WHERE booking_reference = "BK-000270"
           AND currency = "AED"
           AND status <> "void"
           AND unallocated_amount = 844.00
         ORDER BY id ASC
         LIMIT 1'
    );
    $creditReceiptId = (int) ($creditReceiptStatement->fetchColumn() ?: 0);
    if ($creditReceiptId > 0) {
        (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
            'booking_id' => (int) $booking['id'],
            'receipt_action' => 'no_receipt',
            'receipt_scope' => 'whole_invoice',
            'receipt_currency' => 'AED',
            'received_amount' => '0',
            'receipt_date' => '2026-07-21',
            'payment_method' => 'cash',
            'charges_amount' => '0',
            'receipt_status' => 'received',
            'customer_credit_receipt_id' => $creditReceiptId,
            'customer_credit_apply_amount' => '844',
            'advance_receipt_id' => '',
            'advance_apply_amount' => '0',
        ], 1, [(int) $booking['branch_id']]);
    }

    $document = (new \App\Services\OperationalOutputService($app))->buildOutputDocument(
        (int) $booking['id'],
        'customer_settlement_receipt',
        [(int) $booking['branch_id']],
        null,
        null,
        null,
        1
    );

    $history = is_array($document['customerPaymentFoundation']['invoicePaymentHistory'] ?? null)
        ? $document['customerPaymentFoundation']['invoicePaymentHistory']
        : [];
    $transferredCreditRows = array_values(array_filter(
        $history,
        static fn (array $row): bool => (string) ($row['bookingReference'] ?? '') === 'BK-000277'
            && (string) ($row['receiptBookingReference'] ?? '') === 'BK-000270'
            && abs((float) ($row['receivableAmountAllocated'] ?? 0) - 844.00) <= 0.005
    ));

    $check(
        'Customer settlement output is accepted as a printable document',
        (string) ($document['outputType'] ?? '') === 'customer_settlement_receipt'
            && (string) ($document['outputTypeLabel'] ?? '') === 'Customer Payment Receipt'
    );
    $check(
        'BK-000277 customer receipt includes the AED 844 credit transferred from BK-000270',
        count($transferredCreditRows) === 1
    );

    $renderedOutput = (string) $app->get('view')->render('workspace/output', $document, 'layouts/print');
    $settlementHeaderText = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($renderedOutput)));
    $check(
        'Rendered branded BK-000277 receipt shows AED 844 paid and AED 606 due',
        str_contains($renderedOutput, 'class="receipt-sheet"')
            && str_contains($renderedOutput, 'Customer Payment Receipt')
            && preg_match('/Current Invoice Amount\s*AED 1,450\.00/', $settlementHeaderText) === 1
            && preg_match('/Total Paid\s*AED 844\.00/', $settlementHeaderText) === 1
            && preg_match('/Invoice Outstanding Balance\s*AED 606\.00/', $settlementHeaderText) === 1
    );

    $oldReceiptStatement = $db->query(
        'SELECT b.id AS booking_id, b.branch_id, cr.id AS receipt_id
         FROM bookings b
         INNER JOIN customer_receipts cr ON cr.booking_reference = b.booking_reference
         WHERE b.booking_reference = "BK-000270"
         ORDER BY cr.id ASC
         LIMIT 1'
    );
    $oldReceipt = $oldReceiptStatement->fetch() ?: [];
    if ((int) ($oldReceipt['receipt_id'] ?? 0) > 0) {
        $oldReceiptDocument = (new \App\Services\OperationalOutputService($app))->buildOutputDocument(
            (int) $oldReceipt['booking_id'],
            'customer_receipt',
            [(int) $oldReceipt['branch_id']],
            (int) $oldReceipt['receipt_id'],
            null,
            null,
            1
        );
        $oldReceiptOutput = (string) $app->get('view')->render('workspace/output', $oldReceiptDocument, 'layouts/print');
        $check(
            'An old saved receipt reopens through the branded customer payment template',
            str_contains($oldReceiptOutput, 'class="receipt-sheet"')
                && str_contains($oldReceiptOutput, 'Customer Payment Receipt')
                && ! str_contains($oldReceiptOutput, 'Operational output generated from the current saved booking record.')
        );
    } else {
        $check('An old saved receipt reopens through the branded customer payment template', false);
    }

    $accountLedger = (new \App\Services\AccountLedgerService($app))->report([
        'dateFrom' => '',
        'dateTo' => '',
        'currency' => 'AED',
        'businessSourceId' => 0,
        'customerName' => '',
        'bookingReference' => 'BK-000277',
    ], [(int) $booking['branch_id']]);
    $accountSummary = (array) ($accountLedger['summaryRows'][0] ?? []);
    $check(
        'BK-000277 Account Ledger summary shows only the AED 844 credit transferred in as Debit',
        (string) ($accountSummary['total_debit'] ?? '') === '844.00'
            && (string) ($accountSummary['total_credit'] ?? '') === '0.00'
    );
    $sourceAccountLedger = (new \App\Services\AccountLedgerService($app))->report([
        'dateFrom' => '',
        'dateTo' => '',
        'currency' => 'AED',
        'businessSourceId' => 0,
        'customerName' => '',
        'bookingReference' => 'BK-000270',
    ], [(int) $booking['branch_id']]);
    $sourceAccountSummary = (array) ($sourceAccountLedger['summaryRows'][0] ?? []);
    $check(
        'BK-000270 summary retains its original non-refund totals',
        (string) ($sourceAccountSummary['total_debit'] ?? '') === '1,070.00'
            && (string) ($sourceAccountSummary['total_credit'] ?? '') === '1,015.00'
    );

    $zeroCashResult = (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id' => (int) $booking['id'],
        'receipt_action' => 'no_receipt',
        'receipt_scope' => 'whole_invoice',
        'receipt_currency' => 'AED',
        'received_amount' => '0',
        'receipt_date' => '2026-07-21',
        'payment_method' => 'cash',
        'charges_amount' => '0',
        'receipt_status' => 'received',
        'customer_credit_receipt_id' => '',
        'customer_credit_apply_amount' => '0',
        'advance_receipt_id' => '',
        'advance_apply_amount' => '0',
    ], 1, [(int) $booking['branch_id']]);
    $check(
        'Zero-cash backend save accepts no treasury account and creates no receipt',
        (int) ($zeroCashResult['receipt']['id'] ?? 0) === 0
    );

    $view = file_get_contents(BASE_PATH . '/app/Views/workspace/output.php') ?: '';
    $controller = file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php') ?: '';
    $station = file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php') ?: '';
    $outputService = file_get_contents(BASE_PATH . '/app/Services/OperationalOutputService.php') ?: '';
    $javascript = file_get_contents(BASE_PATH . '/public/assets/js/workspace.js') ?: '';
    $check(
        'Settlement output uses the same branded receipt layout as cash receipts',
        str_contains($view, "['customer_receipt', 'customer_settlement_receipt', 'booking_summary_receipt', 'service_refund_receipt']")
            && str_contains($view, "['customer_receipt', 'customer_settlement_receipt', 'booking_summary_receipt']")
    );
    $check(
        'The obsolete saved-but-not-reopenable receipt failure has been removed',
        ! str_contains($controller, 'The receipt was saved but could not be reopened for printing.')
            && str_contains($controller, "'print_receipt_url' => \$printReceiptUrl")
    );
    $check(
        'Credit-only saves return a receipt-view URL without forcing the browser print dialog',
        str_contains($controller, "doc=customer_settlement_receipt")
            && ! str_contains($controller, "doc=customer_settlement_receipt&auto_print=1")
            && str_contains($controller, "'print_receipt_url' => \$printReceiptUrl")
    );
    $check(
        'A booking without payment uses the booking receipt instead of a fake customer receipt',
        str_contains($controller, "&doc=booking_summary_receipt")
            && str_contains($controller, "'booking-summary-' . \$bookingId")
            && str_contains($station, "&doc=booking_summary_receipt")
            && ! str_contains($outputService, "'SETTLEMENT-'")
    );
    $check(
        'Consumed customer credit is cleared and reloaded before preview recalculation',
        str_contains($javascript, 'resetConsumedCustomerCreditControls')
            && str_contains($javascript, "paymentCustomerCreditAmountInput.value = '0'")
            && str_contains($javascript, 'await refreshPaymentAdvanceControls()')
    );
    $check(
        'Selected customer credit survives invoice autosave before the payment request is submitted',
        str_contains($javascript, 'const selectedCustomerCreditReceiptId = paymentCustomerCreditSelect?.value')
            && str_contains($javascript, 'const preserveSelectedSettlementOn = (formData) =>')
            && str_contains($javascript, "formData.set('customer_credit_receipt_id', selectedCustomerCreditReceiptId)")
            && str_contains($javascript, "formData.set('receipt_currency', selectedPaymentCurrency)")
            && substr_count($javascript, 'preserveSelectedSettlementOn(formData);') === 2
    );
    $check(
        'A valid settlement URL can print even when no new cash receipt ID exists',
        str_contains($javascript, "return printUrl !== '';")
            && ! str_contains($javascript, "if (receiptId <= 0 || printUrl === '')")
    );
    $check(
        'Saving an applied customer credit opens its receipt instead of showing a ready-to-print message',
        str_contains($javascript, "openCustomerReceiptWindowOnce('credit-settlement-save', receiptWindow)")
            && ! str_contains($javascript, 'Receipt is ready to print.')
    );
    $check(
        'Receipt opening preserves the desktop launcher while retaining a normal-browser fallback',
        str_contains($javascript, "window.open(normalizedUrl, '_blank')")
            && str_contains($javascript, "typeof window.travelLauncher.config === 'function'")
            && str_contains($javascript, "receipt-open:launcher-child-managed")
            && str_contains($javascript, 'navigateCustomerReceiptWindow(printUrl, reservedWindow)')
            && str_contains($javascript, 'window.location.assign(normalizedUrl)')
            && ! str_contains($javascript, "window.open('about:blank', '_blank')")
            && ! str_contains($javascript, 'Receipt could not be opened yet. Please review the payment and try again.')
    );
    $css = file_get_contents(BASE_PATH . '/public/assets/css/app.css') ?: '';
    $check(
        'Branded receipt colors are preserved by the browser print engine',
        str_contains($css, '-webkit-print-color-adjust: exact;')
            && str_contains($css, 'print-color-adjust: exact;')
    );
    $stationView = file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php') ?: '';
    $check(
        'Zero new cash disables the treasury account and bypasses account/detail requirements',
        str_contains($javascript, 'paymentTreasuryAccountSelect.disabled = !hasNewCashPayment')
            && str_contains($javascript, "'Not used — no new cash received'")
            && str_contains($javascript, 'Math.max(toNumber(receivedNowInput?.value || 0), 0) <= 0.005')
            && str_contains($stationView, 'paymentTreasurySelect.disabled = !requiresTreasury || !hasNewCashPayment')
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Customer credit settlement receipt regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Customer credit settlement receipt regression passed.' . PHP_EOL;
