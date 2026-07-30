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

echo 'Exchange-settlement FX-gain regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;
$failures = [];
$check = static function (bool $ok, string $message, mixed $detail = null) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $ok) {
        $failures[] = $message;
    }
};

$bookingStatement = $db->query(
    'SELECT b.id, b.booking_reference, b.branch_id
     FROM bookings b
     WHERE b.branch_id = 2 AND b.lead_traveler_id IS NOT NULL
     ORDER BY CASE WHEN b.booking_reference = "BK-000196" THEN 0 ELSE 1 END, b.id
     LIMIT 1'
);
$booking = $bookingStatement->fetch(PDO::FETCH_ASSOC) ?: null;
if ($booking === null) {
    fwrite(STDERR, '[FAIL] A branch-2 booking with a customer is required.' . PHP_EOL);
    exit(1);
}

$db->beginTransaction();
try {
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $lineReference = 'TEST-FX-' . date('His');
    $receivableId = $payments->createReceivableItem([
        'branch_id' => (int) $booking['branch_id'],
        'booking_reference' => (string) $booking['booking_reference'],
        'service_line_reference' => $lineReference,
        'due_group' => 'service_sale',
        'currency' => 'PKR',
        'due_amount' => 1000.00,
        'status' => 'open',
        'remarks' => 'Rollback-only FX gain regression',
        'actor_user_id' => 1,
    ]);

    $result = (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
        'settlement_mode' => 'exchange',
        'booking_id' => (int) $booking['id'],
        'receipt_date' => date('Y-m-d'),
        'receipt_currency' => 'AED',
        'received_amount' => 500.00,
        'payment_method' => 'cash',
        'treasury_account_id' => 34,
        'receipt_status' => 'received',
        'charges_amount' => 0.00,
        'settlement_target_receivable_id' => $receivableId,
        'settlement_target_currency' => 'PKR',
        'settlement_target_receivable_amount' => 1000.00,
        'settlement_target_payment_amount' => 485.00,
        'settlement_rate_from_currency' => 'PKR',
        'settlement_rate_to_currency' => 'AED',
        'settlement_exchange_rate' => 0.485,
        'settlement_exchange_rate_effective_date' => date('Y-m-d'),
    ], 1, [(int) $booking['branch_id']]);

    $receipt = (array) ($result['receipt'] ?? []);
    $recognitionStatement = $db->prepare(
        'SELECT x.amount, x.income_account_code, je.source_type,
                SUM(CASE WHEN coa.code = "CUSTOMER_CREDIT" THEN jel.debit_amount ELSE 0 END) liability_debit,
                SUM(CASE WHEN coa.code = "FX_GAIN" THEN jel.credit_amount ELSE 0 END) gain_credit
         FROM customer_credit_income_recognitions x
         INNER JOIN journal_entries je ON je.id = x.journal_entry_id
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE x.customer_receipt_id = :receipt_id
         GROUP BY x.id, x.amount, x.income_account_code, je.source_type'
    );
    $recognitionStatement->execute(['receipt_id' => (int) ($receipt['id'] ?? 0)]);
    $recognition = $recognitionStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $check(
        abs((float) ($receipt['received_amount'] ?? 0) - 500.00) <= 0.005
        && abs((float) ($receipt['allocated_amount'] ?? 0) - 485.00) <= 0.005,
        'AED 500 receipt consumes AED 485 for the cross-currency settlement',
        $receipt
    );
    $check(
        abs((float) ($receipt['unallocated_amount'] ?? 0)) <= 0.005
        && ($receipt['status'] ?? '') === 'fully_allocated',
        'The AED 15 exchange surplus is not left as customer credit',
        $receipt
    );
    $check(
        abs((float) ($recognition['amount'] ?? 0) - 15.00) <= 0.005
        && abs((float) ($recognition['liability_debit'] ?? 0) - 15.00) <= 0.005
        && abs((float) ($recognition['gain_credit'] ?? 0) - 15.00) <= 0.005,
        'AED 15 is automatically posted as balanced FX profit',
        $recognition
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Exchange-settlement FX-gain regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo PHP_EOL . 'Exchange-settlement FX-gain regression passed.' . PHP_EOL;
