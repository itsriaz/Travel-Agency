<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$paymentRepository = new \App\Repositories\CustomerPaymentRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
$receiptNo = $paymentRepository->nextReceiptNumber();
$receiptId = $paymentRepository->createReceipt([
    'branch_id' => 1,
    'booking_reference' => 'BK-000023',
    'receipt_no' => $receiptNo,
    'receipt_date' => '2026-05-11',
    'currency' => 'PKR',
    'received_amount' => 11400.00,
    'payment_method' => 'cash',
    'reference_number' => null,
    'bank_card_detail' => null,
    'charges_amount' => 0,
    'status' => 'received',
    'exchange_rate_to_booking' => 0.01315789,
    'remarks' => 'Task 2.8B cross currency validation',
    'actor_user_id' => 1,
]);
$accountingRepository->postCustomerReceiptRecorded([
    'branch_id' => 1,
    'booking_reference' => 'BK-000023',
    'customer_receipt_id' => $receiptId,
    'receipt_no' => $receiptNo,
    'received_amount' => 11400.00,
    'charges_amount' => 0,
    'payment_method' => 'cash',
    'entry_date' => '2026-05-11',
    'currency' => 'PKR',
    'actor_user_id' => 1,
]);
$result = $paymentRepository->allocateReceipt($receiptId, 256, 150.00, 0.01315789, 'Task 2.8B FX validation', 1);
$allocationId = (int) ($result['allocation_id'] ?? 0);
$accountingRepository->postCustomerReceiptAllocation([
    'branch_id' => 1,
    'booking_reference' => 'BK-000022',
    'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
    'service_line_reference' => 'SV-001',
    'customer_receivable_item_id' => 256,
    'customer_receipt_id' => $receiptId,
    'allocated_amount' => (float) ($result['allocated_amount'] ?? 0),
    'entry_date' => '2026-05-11',
    'currency' => 'AED',
    'actor_user_id' => 1,
]);
$db = $app->get('db');
$stmt = $db->prepare('SELECT id, customer_receipt_id, customer_receivable_item_id, allocated_amount, receivable_currency, receivable_amount_allocated, payment_currency, payment_amount_consumed, rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_used, exchange_rate_effective_date FROM customer_receipt_allocations WHERE id = ?');
$stmt->execute([$allocationId]);
echo json_encode(['receipt_no' => $receiptNo, 'receipt_id' => $receiptId, 'allocation' => $stmt->fetch(PDO::FETCH_ASSOC), 'result' => $result], JSON_PRETTY_PRINT), PHP_EOL;
