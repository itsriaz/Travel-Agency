<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\Core\App;
use App\Repositories\TreasuryRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new TreasuryRepository($app);
$statement = $db->query(
    'SELECT id FROM treasury_transactions
     WHERE status = "posted"
       AND transaction_type IN (
           "cash_deposit_to_bank", "bank_withdrawal_to_cash",
           "bank_to_bank_transfer", "cash_to_cash_transfer"
       ) ORDER BY id'
);
$transferIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
$failures = [];
foreach ($transferIds as $id) {
    try {
        $treasury->assertPostedTransferJournalIntegrity($id);
    } catch (Throwable $exception) {
        $failures[] = ['transfer_id' => $id, 'problem' => $exception->getMessage()];
    }
}

echo json_encode([
    'inspection_only' => true,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
    'posted_transfers_checked' => count($transferIds),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($failures === [] ? 0 : 1);
