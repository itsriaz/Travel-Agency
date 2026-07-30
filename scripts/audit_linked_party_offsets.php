<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$party = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--party=')) {
        $party = trim(substr($argument, strlen('--party=')));
    }
}

$params = [];
$where = '';
if ($party !== '') {
    $where = ' AND (bs.name LIKE :account_name OR bs.code LIKE :account_code OR s.name LIKE :supplier_name OR s.code LIKE :supplier_code)';
    $params = [
        'account_name' => '%' . $party . '%',
        'account_code' => '%' . $party . '%',
        'supplier_name' => '%' . $party . '%',
        'supplier_code' => '%' . $party . '%',
    ];
}

$linkStatement = $db->prepare(
    'SELECT l.id, l.business_source_id, l.supplier_id,
            bs.code AS account_code, bs.name AS account_name,
            s.code AS supplier_code, s.name AS supplier_name
     FROM business_source_supplier_links l
     INNER JOIN business_sources bs ON bs.id = l.business_source_id
     INNER JOIN suppliers s ON s.id = l.supplier_id
     WHERE 1=1' . $where . '
     ORDER BY bs.name, s.name'
);
$linkStatement->execute($params);
$links = $linkStatement->fetchAll() ?: [];

echo 'Linked account/supplier adjustment audit (read-only)' . PHP_EOL;
echo 'Filter: ' . ($party !== '' ? $party : 'All linked parties') . PHP_EOL;

if ($links === []) {
    echo 'No active account/supplier link matched the filter.' . PHP_EOL;
    exit(0);
}

$accountStatement = $db->prepare(
    'SELECT br.name AS branch_name, cri.currency,
            COUNT(DISTINCT cri.id) AS invoice_positions,
            ROUND(SUM(cri.due_amount), 2) AS original_account_recovery,
            ROUND(SUM(COALESCE(used.amount, 0)), 2) AS already_adjusted,
            ROUND(SUM(GREATEST(cri.due_amount - COALESCE(used.amount, 0), 0)), 2) AS remaining_account_recovery
     FROM bookings b
     INNER JOIN customer_receivable_items cri ON cri.booking_reference = b.booking_reference
     INNER JOIN branches br ON br.id = cri.branch_id
     LEFT JOIN (
        SELECT aa.customer_receivable_item_id, SUM(aa.allocated_amount) AS amount
        FROM counterparty_offset_account_allocations aa
        INNER JOIN counterparty_offsets o ON o.id = aa.counterparty_offset_id AND o.status = "posted"
        GROUP BY aa.customer_receivable_item_id
     ) used ON used.customer_receivable_item_id = cri.id
     WHERE b.business_source_id = :business_source_id AND cri.due_amount > 0.005
     GROUP BY br.name, cri.currency
     ORDER BY br.name, cri.currency'
);
$payableStatement = $db->prepare(
    'SELECT br.name AS branch_name, so.currency,
            COUNT(DISTINCT so.id) AS supplier_positions,
            ROUND(SUM(so.gross_amount), 2) AS original_supplier_cost,
            ROUND(SUM(so.net_payable_amount), 2) AS remaining_supplier_payable
     FROM supplier_obligations so
     INNER JOIN branches br ON br.id = so.branch_id
     WHERE so.supplier_id = :supplier_id AND so.gross_amount > 0.005
     GROUP BY br.name, so.currency
     ORDER BY br.name, so.currency'
);
$offsetStatement = $db->prepare(
    'SELECT o.id, o.offset_no, o.offset_date, br.name AS branch_name, o.currency, o.amount, o.status,
            COUNT(DISTINCT aa.id) AS account_positions_used,
            COUNT(DISTINCT pa.id) AS supplier_positions_used
     FROM counterparty_offsets o
     INNER JOIN branches br ON br.id = o.branch_id
     LEFT JOIN counterparty_offset_account_allocations aa ON aa.counterparty_offset_id = o.id
     LEFT JOIN counterparty_offset_payable_allocations pa ON pa.counterparty_offset_id = o.id
     WHERE o.link_id = :link_id
     GROUP BY o.id, o.offset_no, o.offset_date, br.name, o.currency, o.amount, o.status
     ORDER BY o.id'
);

foreach ($links as $link) {
    echo PHP_EOL;
    echo sprintf(
        'Link #%d: %s [%s] <-> %s [%s]%s',
        (int) $link['id'],
        (string) $link['account_name'],
        (string) $link['account_code'],
        (string) $link['supplier_name'],
        (string) $link['supplier_code'],
        PHP_EOL
    );

    $accountStatement->execute(['business_source_id' => (int) $link['business_source_id']]);
    foreach ($accountStatement->fetchAll() ?: [] as $row) {
        echo sprintf(
            '  Account recovery | %s | %s | %d invoice(s) | original %.2f | adjusted %.2f | remaining %.2f%s',
            (string) $row['branch_name'],
            (string) $row['currency'],
            (int) $row['invoice_positions'],
            (float) $row['original_account_recovery'],
            (float) $row['already_adjusted'],
            (float) $row['remaining_account_recovery'],
            PHP_EOL
        );
    }

    $payableStatement->execute(['supplier_id' => (int) $link['supplier_id']]);
    foreach ($payableStatement->fetchAll() ?: [] as $row) {
        echo sprintf(
            '  Supplier payable | %s | %s | %d invoice(s) | original %.2f | remaining %.2f%s',
            (string) $row['branch_name'],
            (string) $row['currency'],
            (int) $row['supplier_positions'],
            (float) $row['original_supplier_cost'],
            (float) $row['remaining_supplier_payable'],
            PHP_EOL
        );
    }

    $offsetStatement->execute(['link_id' => (int) $link['id']]);
    $offsets = $offsetStatement->fetchAll() ?: [];
    if ($offsets === []) {
        echo '  Adjustments: none posted.' . PHP_EOL;
        continue;
    }
    foreach ($offsets as $row) {
        echo sprintf(
            '  Adjustment | %s | %s | %s %.2f | %s | covers %d account invoice(s) and %d supplier invoice(s)%s',
            (string) $row['offset_no'],
            (string) $row['branch_name'],
            (string) $row['currency'],
            (float) $row['amount'],
            strtoupper((string) $row['status']),
            (int) $row['account_positions_used'],
            (int) $row['supplier_positions_used'],
            PHP_EOL
        );
    }
}
