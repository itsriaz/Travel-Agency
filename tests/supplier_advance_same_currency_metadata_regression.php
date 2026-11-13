<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$statement = $db->query('SELECT x.id, x.applied_amount, x.advance_amount_consumed,
        x.obligation_amount_applied
    FROM supplier_advance_applications x
    INNER JOIN supplier_advances a ON a.id = x.supplier_advance_id
    INNER JOIN supplier_obligations o ON o.id = x.supplier_obligation_id
    WHERE a.currency = o.currency');
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $row) {
    $amount = (float) $row['applied_amount'];
    if (abs($amount - (float) $row['advance_amount_consumed']) > 0.005
        || abs($amount - (float) $row['obligation_amount_applied']) > 0.005) {
        throw new RuntimeException('Same-currency supplier advance application #' . $row['id']
            . ' has stale detail amounts. Review it with scripts/reconcile_supplier_advance_metadata.php before correction.');
    }
}

echo '[PASS] ' . count($rows) . ' same-currency supplier advance applications have matching source and obligation amounts.' . PHP_EOL;
