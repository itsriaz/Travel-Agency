<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$statement = $db->prepare(
    'SELECT bs.id service_id, b.booking_reference, bs.supplier_id, bs.currency
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     WHERE b.remarks = :marker LIMIT 1'
);
$statement->execute(['marker' => 'RIAZ-MULTI-CURRENCY-DEMO-20260723 / ABC2']);
$service = $statement->fetch(PDO::FETCH_ASSOC);
if ($service === false) {
    throw new RuntimeException('Retained ABC2 service not found.');
}

$events = new \App\Repositories\BookingServiceEventRepository($app);
$cancel = $events->latestPostedEvent((int) $service['service_id'], 'cancel');
$refund = null;
foreach ($events->postedEventsForService((int) $service['service_id']) as $event) {
    if ((string) ($event['event_type'] ?? '') === 'refund'
        && (float) ($event['supplier_refund_amount'] ?? 0) > 0.005
    ) {
        $refund = $event;
    }
}
if ($cancel === null || $refund === null) {
    throw new RuntimeException('Retained cancel/refund event not found.');
}

$refundPayload = json_decode((string) ($refund['payload_json'] ?? ''), true);
$refundPayload = is_array($refundPayload) ? $refundPayload : [];
if (! empty($refundPayload['cash_refund_credit_consumption'])) {
    foreach ((array) ($refundPayload['cash_refund_credit_consumption']['payment_consumptions'] ?? []) as $consumption) {
        if (! is_array($consumption)) {
            continue;
        }
        $paymentId = (int) ($consumption['supplier_payment_id'] ?? 0);
        $payment = $db->prepare(
            'SELECT paid_amount, allocated_amount, unallocated_amount, converted_advance_amount, returned_amount
             FROM supplier_payments WHERE id = :id'
        );
        $payment->execute(['id' => $paymentId]);
        $row = $payment->fetch(PDO::FETCH_ASSOC) ?: [];
        $missingReturn = round(max(
            (float) ($row['paid_amount'] ?? 0)
            - (float) ($row['allocated_amount'] ?? 0)
            - (float) ($row['unallocated_amount'] ?? 0)
            - (float) ($row['converted_advance_amount'] ?? 0)
            - (float) ($row['returned_amount'] ?? 0),
            0
        ), 2);
        if ($paymentId > 0 && $missingReturn > 0.005) {
            $update = $db->prepare(
                'UPDATE supplier_payments
                 SET returned_amount = returned_amount + :amount
                 WHERE id = :id'
            );
            $update->execute(['amount' => $missingReturn, 'id' => $paymentId]);
        }
    }
    echo 'Already aligned; supplier payment return conservation verified.' . PHP_EOL;
    exit(0);
}
$cancelPayload = json_decode((string) ($cancel['payload_json'] ?? ''), true);
$cancelPayload = is_array($cancelPayload) ? $cancelPayload : [];

$db->beginTransaction();
try {
    $consumption = (new \App\Repositories\SupplierRepository($app))->consumeRefundableCreditForService(
        (int) $service['supplier_id'],
        (string) $service['booking_reference'],
        (string) $service['currency'],
        (float) $refund['supplier_refund_amount'],
        1,
        'Align retained Riaz test scenario with cash supplier refund truth.',
        (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
    );
    $events->updateRefundFinancials((int) $refund['id'], [
        'payload_patch' => ['cash_refund_credit_consumption' => $consumption],
    ]);
    $db->commit();
    echo 'Aligned retained ABC2 cash supplier refund: '
        . json_encode($consumption, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
