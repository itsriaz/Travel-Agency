<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$paymentRepository = new \App\Repositories\CustomerPaymentRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
$receipt = $paymentRepository->findReceiptById(171);
$receivable = $paymentRepository->findReceivableById(256);
if ($receipt === null || $receivable === null) {
    throw new RuntimeException('Receipt or receivable not found.');
}
$result = $paymentRepository->allocateReceipt(171, 256, 900.00, 1.0, 'Local validation repair allocation.', 1);
$allocationId = (int) ($result['allocation_id'] ?? 0);
$allocatedAmount = (float) ($result['allocated_amount'] ?? 0);
if ($allocationId <= 0 || $allocatedAmount <= 0) {
    throw new RuntimeException('Allocation failed.');
}
$accountingRepository->postCustomerReceiptAllocation([
    'branch_id' => (int) ($receivable['branch_id'] ?? $receipt['branch_id']),
    'booking_reference' => (string) ($receivable['booking_reference'] ?? $receipt['booking_reference']),
    'source_reference' => (string) $receipt['receipt_no'] . '-ALLOC-' . $allocationId,
    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
    'customer_receivable_item_id' => (int) $receivable['id'],
    'customer_receipt_id' => 171,
    'allocated_amount' => round($allocatedAmount, 2),
    'entry_date' => (string) $receipt['receipt_date'],
    'currency' => (string) ($receivable['currency'] ?? $receipt['currency']),
    'actor_user_id' => 1,
]);
echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
