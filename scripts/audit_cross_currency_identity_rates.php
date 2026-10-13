<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$queries = [
    'active_service_pricing' => '
        SELECT b.booking_reference, s.id AS service_id, s.cost_currency, s.currency AS invoice_currency,
               s.purchase_cost, s.pricing_exchange_rate, s.service_charge_currency,
               s.service_charge, s.service_charge_exchange_rate
          FROM booking_services s
          JOIN bookings b ON b.id = s.booking_id
         WHERE s.is_active = 1
           AND ((s.cost_currency <> s.currency AND s.purchase_cost > 0
                 AND ABS(s.pricing_exchange_rate - 1) <= 0.00000001)
             OR (s.service_charge_currency <> s.currency
                 AND ABS(s.service_charge + s.vat - s.discount_amount) > 0.005
                 AND ABS(s.service_charge_exchange_rate - 1) <= 0.00000001))
         ORDER BY b.booking_reference, s.id',
    'booking_rates' => '
        SELECT b.booking_reference, r.booking_id, r.from_currency, r.to_currency,
               r.rate_value, r.effective_date
          FROM booking_exchange_rates r
          JOIN bookings b ON b.id = r.booking_id
         WHERE r.from_currency <> r.to_currency
           AND ABS(r.rate_value - 1) <= 0.00000001
         ORDER BY b.booking_reference, r.effective_date',
    'daily_rates' => '
        SELECT id, from_currency, to_currency, rate_value, effective_date
          FROM exchange_rates
         WHERE from_currency <> to_currency
           AND ABS(rate_value - 1) <= 0.00000001
         ORDER BY effective_date, id',
];

$result = ['inspection_only' => true];
foreach ($queries as $name => $sql) {
    $statement = $db->prepare($sql);
    $statement->execute();
    $result[$name] = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
