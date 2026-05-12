<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$sql = 'SELECT a.id, r.receipt_no, r.currency AS receipt_currency, i.currency AS receivable_currency, a.allocated_amount, a.receivable_currency AS stored_receivable_currency, a.receivable_amount_allocated, a.payment_currency, a.payment_amount_consumed, a.rate_from_currency, a.rate_to_currency, a.exchange_rate, a.exchange_rate_used, a.exchange_rate_effective_date FROM customer_receipt_allocations a INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id ORDER BY a.id DESC LIMIT 5';
foreach (($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    echo json_encode($row), PHP_EOL;
}
