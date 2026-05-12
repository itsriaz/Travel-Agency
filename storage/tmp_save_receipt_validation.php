<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\CustomerReceiptWorkspaceService($app);
$result = $service->saveReceipt([
    'booking_id' => 289,
    'receipt_currency' => 'PKR',
    'received_amount' => '2500',
    'receipt_date' => '2026-05-11',
    'payment_method' => 'cash',
    'receipt_status' => 'received',
    'charges_amount' => '0',
    'receipt_remarks' => 'Task 2.7 validation receipt',
], 1, [1,2]);
echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
