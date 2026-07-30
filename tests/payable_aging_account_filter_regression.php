<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\ReportRepository;

$app = (isset($app) && $app instanceof App) ? $app : App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new ReportRepository($app);

$branchIds = array_map(
    'intval',
    $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
);
$accountIds = array_map(
    'intval',
    $db->query(
        'SELECT DISTINCT b.business_source_id
         FROM supplier_obligations so
         INNER JOIN bookings b ON b.booking_reference = so.booking_reference
         WHERE b.business_source_id IS NOT NULL
           AND so.net_payable_amount > 0
           AND so.status IN ("open", "partially_covered")
         ORDER BY b.business_source_id'
    )->fetchAll(PDO::FETCH_COLUMN)
);

$failures = [];
$checkedRows = 0;
$mismatches = [];

foreach ($accountIds as $accountId) {
    $rows = $repository->payableAging(
        $branchIds,
        date('Y-m-d'),
        null,
        null,
        '',
        0,
        $accountId
    );

    foreach ($rows as $row) {
        $checkedRows++;
        $statement = $db->prepare(
            'SELECT business_source_id
             FROM bookings
             WHERE booking_reference = :booking_reference
             LIMIT 1'
        );
        $statement->execute(['booking_reference' => (string) ($row['booking_reference'] ?? '')]);
        $actualAccountId = (int) ($statement->fetchColumn() ?: 0);

        if ($actualAccountId !== $accountId) {
            $mismatches[] = [
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'selected_account_id' => $accountId,
                'booking_account_id' => $actualAccountId,
            ];
        }
    }
}

$passed = $mismatches === [];
echo ($passed ? '[PASS] ' : '[FAIL] ')
    . 'Payable Aging account filter matches only the account holder recorded on each booking'
    . ' - checked=' . $checkedRows;
if (! $passed) {
    echo ', mismatches=' . json_encode($mismatches, JSON_UNESCAPED_SLASHES);
}
echo PHP_EOL;

if (! $passed) {
    exit(1);
}

