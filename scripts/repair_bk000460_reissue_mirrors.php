<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$apply = in_array('--apply', $argv ?? [], true);

echo "BK-000460 reissue mirror repair\nMode: " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";

$statement = $db->prepare(
    'SELECT bs.id, bs.booking_id, bs.line_reference, bs.currency, bs.sale_price, bs.purchase_cost,
            bs.service_charge, bs.final_sale_price, bs.net_profit_loss,
            sat.supplier_cost AS ticket_supplier_cost, sat.sale_amount AS ticket_sale_amount,
            cri.due_amount, so.gross_amount,
            e.id AS event_id, e.service_fee_amount, e.payload_json
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
     INNER JOIN customer_receivable_items cri ON cri.booking_reference = b.booking_reference
        AND cri.service_line_reference = bs.line_reference AND cri.currency = bs.currency
     INNER JOIN supplier_obligations so ON so.booking_reference = b.booking_reference
        AND so.service_line_reference = bs.line_reference AND so.currency = bs.cost_currency
     INNER JOIN booking_service_events e ON e.id = (
        SELECT MAX(e2.id) FROM booking_service_events e2
        WHERE e2.booking_service_id = bs.id AND e2.event_type = "reissue" AND e2.event_status = "posted"
     )
     WHERE b.booking_reference = "BK-000460" AND bs.is_active = 1
     ORDER BY bs.id LIMIT 1'
);
$statement->execute();
$row = $statement->fetch();
if (! is_array($row)) {
    throw new RuntimeException('BK-000460 reissue financial records were not found. No data changed.');
}

$payload = json_decode((string) ($row['payload_json'] ?? ''), true);
$payload = is_array($payload) ? $payload : [];
$supplierCharge = round((float) ($payload['supplier_cost_difference_amount'] ?? $payload['supplier_delta'] ?? 0), 2);
$agencyFee = round((float) ($row['service_fee_amount'] ?? 0), 2);
$targetInvoice = round((float) $row['due_amount'], 2);
$targetSupplier = round((float) $row['gross_amount'], 2);
$targetProfit = round($targetInvoice - $targetSupplier, 2);
$targetServiceCharge = $targetProfit;

$alreadyAligned = round((float) $row['final_sale_price'], 2) === $targetInvoice
    && round((float) $row['purchase_cost'], 2) === $targetSupplier
    && round((float) $row['service_charge'], 2) === $targetServiceCharge
    && round((float) $row['net_profit_loss'], 2) === $targetProfit
    && round((float) $row['ticket_sale_amount'], 2) === $targetInvoice
    && round((float) $row['ticket_supplier_cost'], 2) === $targetSupplier;

echo 'Current: ' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
echo 'Target: ' . json_encode([
    'invoice' => $targetInvoice,
    'supplier_cost' => $targetSupplier,
    'service_charge' => $targetServiceCharge,
    'profit' => $targetProfit,
    'supplier_reissue_charge' => $supplierCharge,
], JSON_UNESCAPED_SLASHES) . "\n";

if ($alreadyAligned) {
    echo "Already aligned. No data changed.\n";
    exit(0);
}
if (! $apply) {
    echo "Dry run complete. Re-run with --apply after taking a database backup.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $update = $db->prepare(
        'UPDATE booking_services
         SET sale_price = :sale_price, purchase_cost = :purchase_cost,
             service_charge = :service_charge, final_sale_price = :final_sale_price,
             net_profit_loss = :net_profit_loss
         WHERE id = :id'
    );
    $update->execute([
        'id' => (int) $row['id'],
        'sale_price' => $targetSupplier,
        'purchase_cost' => $targetSupplier,
        'service_charge' => $targetServiceCharge,
        'final_sale_price' => $targetInvoice,
        'net_profit_loss' => $targetProfit,
    ]);
    $ticket = $db->prepare(
        'UPDATE service_air_ticket SET supplier_cost = :supplier_cost, sale_amount = :sale_amount
         WHERE booking_service_id = :service_id'
    );
    $ticket->execute([
        'service_id' => (int) $row['id'],
        'supplier_cost' => $targetSupplier,
        'sale_amount' => $targetInvoice,
    ]);
    $db->commit();
    echo "Applied. Receivable, payable, service mirrors, and ticket mirrors now agree. No receipt, treasury, allocation, or journal was changed.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
