<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$db->beginTransaction();

try {
    $statement = $db->prepare(
        'SELECT b.booking_reference, bs.id, bs.line_reference, bs.service_type, bs.service_status,
                bs.currency, bs.cost_currency, bs.sale_price, bs.vat_input, bs.purchase_cost,
                bs.final_sale_price, bs.service_charge, bs.net_profit_loss, bs.taxes,
                bs.other_fare, bs.soto_fare, bs.spyi_amount, bs.aq_yr_pk_amount,
                bs.yq_amount, bs.oth_amount, sat.supplier_cost, sat.sale_amount
         FROM bookings b
         JOIN booking_services bs ON bs.booking_id = b.id
         JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
         WHERE b.booking_reference = :booking_reference AND bs.line_reference = :line_reference
         FOR UPDATE'
    );
    $statement->execute(['booking_reference' => 'BK-000517', 'line_reference' => 'SV-001']);
    $services = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($services) !== 1) {
        throw new RuntimeException('Expected exactly one BK-000517 / SV-001 air-ticket service.');
    }

    $service = $services[0];
    $expected = [
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'purchase_cost' => '1351.00',
        'final_sale_price' => '1450.00',
        'service_charge' => '99.00',
        'net_profit_loss' => '99.00',
        'supplier_cost' => '1351.00',
        'sale_amount' => '1450.00',
    ];
    foreach ($expected as $field => $value) {
        if ((string) $service[$field] !== $value) {
            throw new RuntimeException("BK-000517 {$field} has changed; stop for manual review.");
        }
    }
    foreach (['taxes', 'other_fare', 'soto_fare', 'spyi_amount', 'aq_yr_pk_amount', 'yq_amount', 'oth_amount'] as $field) {
        if (abs((float) $service[$field]) > 0.005) {
            throw new RuntimeException("BK-000517 {$field} is no longer zero; stop for manual review.");
        }
    }

    $alreadyApplied = (float) $service['sale_price'] === 1351.0 && (float) $service['vat_input'] === 0.0;
    if (! $alreadyApplied && ((float) $service['sale_price'] !== 0.0 || (float) $service['vat_input'] !== 1351.0)) {
        throw new RuntimeException('BK-000517 fare components do not match the reviewed original values.');
    }

    $receivableStatement = $db->prepare(
        'SELECT currency, due_amount, allocated_amount, outstanding_amount, status
         FROM customer_receivable_items
         WHERE booking_reference = :booking_reference AND service_line_reference = :line_reference'
    );
    $receivableStatement->execute(['booking_reference' => 'BK-000517', 'line_reference' => 'SV-001']);
    $receivables = $receivableStatement->fetchAll(PDO::FETCH_ASSOC);
    $obligationStatement = $db->prepare(
        'SELECT currency, gross_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE booking_reference = :booking_reference AND service_line_reference = :line_reference'
    );
    $obligationStatement->execute(['booking_reference' => 'BK-000517', 'line_reference' => 'SV-001']);
    $obligations = $obligationStatement->fetchAll(PDO::FETCH_ASSOC);
    if (count($receivables) !== 1 || count($obligations) !== 1
        || (string) $receivables[0]['currency'] !== 'AED'
        || (float) $receivables[0]['due_amount'] !== 1450.0
        || (float) $receivables[0]['allocated_amount'] !== 1450.0
        || (string) $obligations[0]['currency'] !== 'AED'
        || (float) $obligations[0]['gross_amount'] !== 1351.0
        || (float) $obligations[0]['net_payable_amount'] !== 1351.0
    ) {
        throw new RuntimeException('BK-000517 receivable or supplier obligation changed; stop for manual review.');
    }

    $report = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'booking_reference' => 'BK-000517',
        'service_id' => (int) $service['id'],
        'already_applied' => $alreadyApplied,
        'market_fare' => ['before' => (float) $service['sale_price'], 'after' => 1351],
        'vat_input' => ['before' => (float) $service['vat_input'], 'after' => 0],
        'unchanged_customer_invoice_AED' => 1450,
        'unchanged_supplier_cost_AED' => 1351,
        'unchanged_profit_AED' => 99,
        'receivable' => $receivables[0],
        'supplier_obligation' => $obligations[0],
    ];

    if ($apply && ! $alreadyApplied) {
        $update = $db->prepare(
            'UPDATE booking_services
             SET sale_price = 1351, vat_input = 0, updated_at = NOW()
             WHERE id = :id AND sale_price = 0 AND vat_input = 1351
               AND purchase_cost = 1351 AND final_sale_price = 1450'
        );
        $update->execute(['id' => (int) $service['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('BK-000517 changed during repair; no update was committed.');
        }

        $audit = $db->prepare(
            'INSERT INTO audit_logs (event_name, actor_user_id, ip_address, user_agent, payload_json, created_at)
             VALUES (:event_name, NULL, NULL, :user_agent, :payload_json, NOW())'
        );
        $audit->execute([
            'event_name' => 'service.market_fare_vat_reclassified',
            'user_agent' => 'CLI repair_bk000517_market_fare.php',
            'payload_json' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
