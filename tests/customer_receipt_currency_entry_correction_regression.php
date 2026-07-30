<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
$app = (isset($app) && $app instanceof \App\Core\App) ? $app : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

echo 'Customer receipt currency-entry correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$failures = [];
$check = static function (bool $condition, string $message, mixed $detail = null) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $condition) {
        $failures[] = $message;
    }
};

$db->beginTransaction();
try {
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $accounting = new \App\Repositories\AccountingRepository($app);
    $bookingReference = 'TEST-CURR-ENTRY-' . date('His');
    $receiptNo = $payments->nextReceiptNumber();
    $receiptId = $payments->createReceipt([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'receipt_no' => $receiptNo,
        'receipt_date' => date('Y-m-d'),
        'currency' => 'AED',
        'received_amount' => 500.00,
        'tendered_amount' => 500.00,
        'returned_amount' => 0.00,
        'payment_method' => 'cash',
        'charges_amount' => 0.00,
        'status' => 'received',
        'treasury_account_id' => 34,
        'remarks' => 'Regression wrong-currency entry',
        'actor_user_id' => 1,
    ]);
    $accounting->postCustomerReceiptRecorded([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'customer_receipt_id' => $receiptId,
        'receipt_no' => $receiptNo,
        'received_amount' => 500.00,
        'charges_amount' => 0.00,
        'payment_method' => 'cash',
        'treasury_account_id' => 34,
        'entry_date' => date('Y-m-d'),
        'currency' => 'AED',
        'actor_user_id' => 1,
    ]);
    $receivableId = $payments->createReceivableItem([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'service_line_reference' => 'SV-001',
        'due_group' => 'service_sale',
        'currency' => 'PKR',
        'due_amount' => 500.00,
        'status' => 'open',
        'remarks' => 'Regression corrected receivable',
        'actor_user_id' => 1,
    ]);

    $result = (new \App\Services\CustomerReceiptCurrencyCorrectionService($app))->correctReleasedReceipt([
        'receipt_id' => $receiptId,
        'new_currency' => 'PKR',
        'new_branch_id' => 1,
        'target_receivable_id' => $receivableId,
        'service_line_reference' => 'SV-001',
        'treatment' => 'migrate_and_allocate',
        'entry_date' => date('Y-m-d'),
        'reason' => 'Regression confirms explicit wrong receipt currency correction.',
    ], 1);

    $original = $payments->findReceiptById($receiptId);
    $replacement = $payments->findReceiptById((int) ($result['replacement_receipt_id'] ?? 0));
    $receivable = $payments->findReceivableById($receivableId);
    $check(($original['status'] ?? '') === 'void', 'Wrong AED receipt is voided with its audit trail', $original);
    $check(
        ($replacement['currency'] ?? '') === 'PKR'
        && (int) ($replacement['treasury_account_id'] ?? 0) === 33
        && abs((float) ($replacement['allocated_amount'] ?? 0) - 500.00) <= 0.005
        && abs((float) ($replacement['unallocated_amount'] ?? 0)) <= 0.005,
        'Replacement PKR receipt uses PKR treasury and allocates automatically',
        $replacement
    );
    $check(
        abs((float) ($receivable['outstanding_amount'] ?? 0)) <= 0.005
        && ($receivable['status'] ?? '') === 'paid',
        'Corrected PKR receivable is settled exactly once',
        $receivable
    );

    $journal = $db->prepare(
        'SELECT je.currency, coa.code, jel.debit_amount, jel.credit_amount
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.source_type = "customer_receipt_wrong_currency_reversed"
           AND jel.customer_receipt_id = :receipt_id'
    );
    $journal->execute(['receipt_id' => $receiptId]);
    $lines = $journal->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $check(count($lines) === 2, 'Old-currency treasury and liability are reversed with a balanced two-line journal', $lines);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Customer receipt currency-entry correction regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo PHP_EOL . 'Customer receipt currency-entry correction regression passed.' . PHP_EOL;
