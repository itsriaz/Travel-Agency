<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$booking = $db->query('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference="BK-001183"')->fetch(PDO::FETCH_ASSOC);
if (! $booking || (int) $booking['branch_id'] !== 1 || (int) $booking['lead_traveler_id'] !== 477) {
    fwrite(STDERR, "BK-001183 fixture is missing.\n");
    exit(1);
}
$payments = new \App\Repositories\CustomerPaymentRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$service = new \App\Services\CustomerReceiptWorkspaceService($app);
$check = static function (bool $ok, string $label): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $ok) {
        throw new RuntimeException($label);
    }
};
$prepare = static function (float $openDue) use ($db, $payments, $accounting): array {
    // All records created here are rolled back with the surrounding transaction.
    $dueUpdate = $db->prepare('UPDATE customer_receivable_items
        SET due_amount=allocated_amount+:due_amount,
            outstanding_amount=:outstanding_amount
        WHERE booking_reference="BK-001183" AND currency="PKR"');
    $dueUpdate->execute(['due_amount' => $openDue, 'outstanding_amount' => $openDue]);
    $ids = [];
    foreach ([14000, 79000] as $index => $amount) {
        $no = 'TEST-POOL-' . bin2hex(random_bytes(5));
        $date = $index === 0 ? '2026-10-01' : '2026-10-02';
        $id = $payments->createReceipt([
            'branch_id' => 1, 'traveler_id' => 531, 'receipt_purpose' => 'booking_payment',
            'booking_reference' => 'BK-000558', 'receipt_no' => $no,
            'receipt_date' => $date, 'currency' => 'PKR', 'received_amount' => $amount,
            'payment_method' => 'cash', 'status' => 'received', 'treasury_account_id' => 33,
            'actor_user_id' => 1,
        ]);
        $accounting->postCustomerReceiptRecorded([
            'branch_id' => 1, 'booking_reference' => 'BK-000558',
            'customer_receipt_id' => $id, 'receipt_no' => $no, 'received_amount' => $amount,
            'charges_amount' => 0, 'payment_method' => 'cash', 'treasury_account_id' => 33,
            'entry_date' => $date, 'currency' => 'PKR', 'actor_user_id' => 1,
        ]);
        $ids[] = $id;
    }
    return $ids;
};
$input = [
    'booking_id' => (int) $booking['id'], 'receipt_action' => 'no_receipt',
    'receipt_scope' => 'whole_invoice', 'receipt_currency' => 'PKR',
    'received_amount' => '0', 'receipt_date' => '2026-10-24',
    'payment_method' => 'cash', 'charges_amount' => '0', 'receipt_status' => 'received',
    'customer_credit_use_pool' => '1', 'customer_credit_apply_amount' => '0',
    'advance_apply_amount' => '0',
];

$db->beginTransaction();
try {
    [$firstId, $secondId] = $prepare(20000);
    $saved = $service->saveReceipt($input, 1, [1]);
    $credit = (array) ($saved['customer_credit_applied'] ?? []);
    $parts = (array) ($credit['receipt_breakdown'] ?? []);
    $check(($saved['receipt'] ?? null) === null, 'Credit transfer creates no new cash receipt');
    $check(abs((float) ($credit['allocated_amount'] ?? 0) - 20000) < 0.005,
        'PKR 20,000 applies to the target invoice');
    $check(count($parts) === 2 && (int) $parts[0]['receipt_id'] === $firstId
        && abs((float) $parts[0]['amount'] - 14000) < 0.005
        && (int) $parts[1]['receipt_id'] === $secondId
        && abs((float) $parts[1]['amount'] - 6000) < 0.005,
        'Oldest credit is consumed first; remainder comes from second receipt');
    $allocationIds = array_map('intval', (array) ($credit['allocation_ids'] ?? []));
    $check(count($allocationIds) === 2, 'Two separate allocation IDs are retained');
    foreach ($parts as $index => $part) {
        $source = $payments->findReceiptById((int) $part['receipt_id']);
        $journal = $db->prepare('SELECT ROUND(SUM(jl.debit_amount),2) AS debit,
            ROUND(SUM(jl.credit_amount),2) AS credit
            FROM journal_entries je JOIN journal_entry_lines jl ON jl.journal_entry_id=je.id
            WHERE je.source_reference=:reference');
        $journal->execute(['reference' => $source['receipt_no'] . '-XFER-' . $allocationIds[$index]]);
        $totals = $journal->fetch(PDO::FETCH_ASSOC) ?: [];
        $check(abs((float) ($totals['debit'] ?? 0) - (float) $part['amount']) < 0.005
            && abs((float) ($totals['credit'] ?? 0) - (float) $part['amount']) < 0.005,
            'Each source posts a balanced transfer journal');
    }
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

$db->beginTransaction();
try {
    [$firstId, $secondId] = $prepare(10000);
    $saved = $service->saveReceipt($input, 1, [1]);
    $parts = (array) ($saved['customer_credit_applied']['receipt_breakdown'] ?? []);
    $check(count($parts) === 1 && (int) $parts[0]['receipt_id'] === $firstId
        && abs((float) $parts[0]['amount'] - 10000) < 0.005,
        'Partial use takes only from the first credit');
    $second = $payments->findReceiptById($secondId);
    $check(abs((float) ($second['unallocated_amount'] ?? 0) - 79000) < 0.005,
        'Unused PKR 79,000 remains available after partial use');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

$db->beginTransaction();
try {
    [$firstId, $secondId] = $prepare(20000);
    $input['customer_credit_apply_amount'] = '500000';
    $input['customer_credit_use_pool'] = '0';
    $input['customer_credit_receipt_id'] = $secondId;
    $skipped = $service->saveReceipt($input, 1, [1]);
    $check(abs((float) ($skipped['customer_credit_applied']['allocated_amount'] ?? 0)) < 0.005,
        'Unchecked credit choice applies zero even if an amount is submitted');
    $second = $payments->findReceiptById($secondId);
    $check(abs((float) ($second['unallocated_amount'] ?? 0) - 79000) < 0.005,
        'Unchecked choice leaves the other credit untouched');
    $due = $db->query('SELECT outstanding_amount FROM customer_receivable_items
        WHERE booking_reference="BK-001183" AND currency="PKR" ORDER BY id LIMIT 1')->fetchColumn();
    $check(abs((float) $due - 20000) < 0.005,
        'Unchecked choice leaves the open invoice unchanged');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

$db->beginTransaction();
try {
    [$firstId, $secondId] = $prepare(95000);
    $input['customer_credit_use_pool'] = '1';
    $input['customer_credit_apply_amount'] = '0';
    $input['received_amount'] = '2000';
    $input['receipt_action'] = 'save';
    $input['treasury_account_id'] = 33;
    $mixed = $service->saveReceipt($input, 1, [1]);
    $check(abs((float) ($mixed['receipt']['received_amount'] ?? 0) - 2000) < 0.005,
        'Mixed payment records exactly PKR 2,000 as new cash');
    $check(abs((float) ($mixed['customer_credit_applied']['allocated_amount'] ?? 0) - 93000) < 0.005,
        'Selected credit automatically covers PKR 93,000 after cash');
    $due = $db->query('SELECT outstanding_amount FROM customer_receivable_items
        WHERE booking_reference="BK-001183" AND currency="PKR" ORDER BY id LIMIT 1')->fetchColumn();
    $check(abs((float) $due) < 0.005, 'Cash plus credit clears the PKR 95,000 invoice remainder');
    $first = $payments->findReceiptById($firstId);
    $second = $payments->findReceiptById($secondId);
    $check(abs((float) $first['unallocated_amount']) < 0.005
        && abs((float) $second['unallocated_amount']) < 0.005,
        'Both source credits are consumed once');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

$db->beginTransaction();
try {
    [$firstId, $secondId] = $prepare(10000);
    $db->exec('UPDATE customer_receipts SET status="void" WHERE id IN (' . $firstId . ',' . $secondId . ')');
    $input['receipt_action'] = 'no_receipt';
    $input['received_amount'] = '0';
    $input['customer_credit_use_pool'] = '1';
    $rejected = false;
    try {
        $service->saveReceipt($input, 1, [1]);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'no longer available');
    }
    $check($rejected, 'Selected credit disappearing before save aborts the payment');
    $second = $payments->findReceiptById($secondId);
    $check(abs((float) ($second['unallocated_amount'] ?? 0) - 79000) < 0.005,
        'Rejected request does not consume the credit');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
echo 'Credit pool regression passed; all fixture changes rolled back.' . PHP_EOL;
