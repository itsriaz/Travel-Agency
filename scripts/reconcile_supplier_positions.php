<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/SupplierPositionReconciliationAudit.php';

$supplierId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--supplier-id=(\d+)$/', $argument, $matches) !== 1) {
        fwrite(STDERR, "Usage: php scripts/reconcile_supplier_positions.php [--supplier-id=349]\n");
        exit(2);
    }
    $supplierId = (int) $matches[1];
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$result = SupplierPositionReconciliationAudit::inspect($db, $supplierId, $app);

echo 'Read-only supplier position reconciliation' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL;
foreach ($result['groups'] as $group) {
    $skipped = $group['exclusions'] !== [];
    $ledgerDiff = (float) ($group['ledger_difference'] ?? 0);
    $status = $skipped || abs($ledgerDiff) > 0.005
        ? 'REVIEW'
        : (abs($group['difference']) > 0.005 ? 'FAIL' : 'PASS');
    echo '[' . $status . '] Supplier #' . $group['supplier_id'] . ' / ' . $group['currency']
        . ' | source net ' . number_format($group['expected_net_position'], 2, '.', '')
        . ' | credit minus open payable ' . number_format($group['actual_net_position'], 2, '.', '')
        . ' | ledger net ' . number_format((float) ($group['ledger_net_position'] ?? 0), 2, '.', '')
        . ' | difference ' . number_format($group['difference'], 2, '.', '')
        . ' | ledger versus operational records ' . number_format($ledgerDiff, 2, '.', '') . PHP_EOL;
    if ($skipped || abs($group['difference']) > 0.005 || $supplierId > 0) {
        echo json_encode([
            'payment_ids' => $group['payment_ids'],
            'advance_ids' => $group['advance_ids'],
            'obligation_ids' => $group['obligation_ids'],
            'offset_ids' => $group['offset_ids'],
            'exclusions' => $group['exclusions'],
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
foreach ($result['exceptions'] as $exception) {
    echo '[EXCEPTION] ' . json_encode($exception, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
foreach ($result['reviews'] as $review) {
    echo '[REVIEW] ' . json_encode($review, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
echo count($result['groups']) . ' supplier/currency groups checked; '
    . count($result['exceptions']) . ' exceptions; ' . count($result['reviews']) . ' ledger comparisons for review.' . PHP_EOL;
exit($result['exceptions'] === [] ? 0 : 1);
