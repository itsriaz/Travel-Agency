<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$repository = new \App\Repositories\ReportRepository($app);
$service = new \App\Services\ReportService($app);
$branchIds = [1, 2];

$sourceRows = $repository->customerLedger($branchIds);
$state = $service->reportState(['report' => 'customer_ledger'], $branchIds, 1, 'screen');
$summaryRows = (array) ($state['customerOutstandingSummaryRows'] ?? []);
$ledgerRows = (array) ($state['rows'] ?? []);

$expectedDebit = 0.0;
$expectedCredit = 0.0;
$expectedInvoiceAmount = 0.0;
$seenReceipts = [];
$seenSupplierPayments = [];
$seenInvoices = [];

foreach ($sourceRows as $row) {
    $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
    $travelerId = (int) ($row['lead_traveler_id'] ?? 0);
    $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
    $serviceReference = trim((string) ($row['service_line_reference'] ?? ''));

    $receiptKey = implode('|', [$bookingReference, $travelerId, $currency]);
    if (! isset($seenReceipts[$receiptKey])) {
        $expectedDebit += (float) ($row['account_customer_received_amount'] ?? 0);
        $seenReceipts[$receiptKey] = true;
    }

    $supplierPaymentKey = implode('|', [$bookingReference, $serviceReference, $travelerId, $currency]);
    if (! isset($seenSupplierPayments[$supplierPaymentKey])) {
        $expectedCredit += (float) ($row['account_supplier_paid_amount'] ?? 0);
        $seenSupplierPayments[$supplierPaymentKey] = true;
    }

    $invoiceKey = implode('|', [$bookingReference, $serviceReference, $currency]);
    if (! isset($seenInvoices[$invoiceKey])) {
        $expectedInvoiceAmount += (float) ($row['original_invoice_amount'] ?? $row['total_due'] ?? 0);
        $seenInvoices[$invoiceKey] = true;
    }
}

$actualDebit = 0.0;
$actualCredit = 0.0;
foreach ($summaryRows as $row) {
    $actualDebit += (float) str_replace(',', '', (string) ($row['total_debit'] ?? 0));
    $actualCredit += (float) str_replace(',', '', (string) ($row['total_credit'] ?? 0));
}

$expectedDebit = round($expectedDebit, 2);
$expectedCredit = round($expectedCredit, 2);
$actualDebit = round($actualDebit, 2);
$actualCredit = round($actualCredit, 2);

echo 'Account Ledger summary regression' . PHP_EOL;
echo 'Expected Debit (customer payments only): ' . number_format($expectedDebit, 2, '.', '') . PHP_EOL;
echo 'Actual Debit: ' . number_format($actualDebit, 2, '.', '') . PHP_EOL;
echo 'Expected Credit (supplier payments only): ' . number_format($expectedCredit, 2, '.', '') . PHP_EOL;
echo 'Actual Credit: ' . number_format($actualCredit, 2, '.', '') . PHP_EOL;

if ($expectedDebit !== $actualDebit || $expectedCredit !== $actualCredit) {
    fwrite(STDERR, 'Account Ledger summary does not match actual payment movements.' . PHP_EOL);
    exit(1);
}

$detailDebit = 0.0;
$detailCredit = 0.0;
$displayedInvoiceAmount = 0.0;
$hasInvoiceSaleRow = false;
$hasCustomerReceiptRow = false;
$hasSupplierPaymentRow = false;

foreach ($ledgerRows as $row) {
    $entry = strtolower(trim((string) ($row['ledger_entry'] ?? '')));
    $detailDebit += (float) str_replace(',', '', (string) ($row['debit_amount'] ?? 0));
    $detailCredit += (float) str_replace(',', '', (string) ($row['credit_amount'] ?? 0));
    $displayedInvoiceAmount += (float) str_replace(',', '', (string) ($row['invoice_amount'] ?? 0));

    $hasInvoiceSaleRow = $hasInvoiceSaleRow || str_contains($entry, 'sold to customer');
    $hasCustomerReceiptRow = $hasCustomerReceiptRow || $entry === 'customer payment received';
    $hasSupplierPaymentRow = $hasSupplierPaymentRow || $entry === 'supplier payment made';
}

if ($hasInvoiceSaleRow) {
    fwrite(STDERR, 'Account Ledger incorrectly contains customer receivable invoice rows.' . PHP_EOL);
    exit(1);
}

if (round($expectedInvoiceAmount, 2) !== round($displayedInvoiceAmount, 2)) {
    fwrite(STDERR, 'Account Ledger invoice amount column does not match original service invoices.' . PHP_EOL);
    exit(1);
}

if ($expectedDebit > 0.005 && ! $hasCustomerReceiptRow) {
    fwrite(STDERR, 'Account Ledger is missing customer receipt cash-in rows.' . PHP_EOL);
    exit(1);
}

if ($expectedCredit > 0.005 && ! $hasSupplierPaymentRow) {
    fwrite(STDERR, 'Account Ledger is missing supplier payment cash-out rows.' . PHP_EOL);
    exit(1);
}

echo 'Detail Debit (includes later refunds): ' . number_format($detailDebit, 2, '.', '') . PHP_EOL;
echo 'Detail Credit (includes later refunds): ' . number_format($detailCredit, 2, '.', '') . PHP_EOL;
echo 'Displayed Invoice Amount: ' . number_format($displayedInvoiceAmount, 2, '.', '') . PHP_EOL;

echo 'Account Ledger summary regression passed.' . PHP_EOL;
