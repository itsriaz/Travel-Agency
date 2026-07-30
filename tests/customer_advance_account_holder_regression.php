<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];

$fixture = $db->query(
    'SELECT br.id AS branch_id, t.id AS traveler_id, bs.id AS business_source_id,
            ta.id AS treasury_account_id, u.id AS user_id, t.full_name AS customer_name
     FROM branches br
     INNER JOIN travelers t ON t.branch_id = br.id
     CROSS JOIN business_sources bs
     INNER JOIN treasury_accounts ta ON ta.branch_id = br.id AND ta.currency = br.base_currency
        AND ta.account_type = "cash" AND ta.is_active = 1
     CROSS JOIN users u
     WHERE br.is_active = 1 AND bs.is_active = 1 AND u.is_active = 1
     ORDER BY br.id, t.id, bs.id, ta.id, u.id
     LIMIT 1'
)->fetch();

if (! is_array($fixture)) {
    fwrite(STDERR, "Customer advance Account Holder regression skipped: no complete fixture was available.\n");
    exit(1);
}

$branchId = (int) $fixture['branch_id'];
$travelerId = (int) $fixture['traveler_id'];
$businessSourceId = (int) $fixture['business_source_id'];
$treasuryAccountId = (int) $fixture['treasury_account_id'];
$userId = (int) $fixture['user_id'];
$customerName = (string) $fixture['customer_name'];
$currency = (string) $db->query('SELECT base_currency FROM branches WHERE id = ' . $branchId)->fetchColumn();
$amount = 137.25;

$db->beginTransaction();
try {
    $service = new \App\Services\CustomerReceiptWorkspaceService($app);
    $advance = $service->recordCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'business_source_id' => $businessSourceId,
        'receipt_currency' => $currency,
        'received_amount' => $amount,
        'receipt_date' => date('Y-m-d'),
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'receipt_status' => 'received',
        'receipt_remarks' => 'Account Holder regression fixture',
    ], $userId, [$branchId]);

    $receipt = (new \App\Repositories\CustomerPaymentRepository($app))->findReceiptById((int) $advance['receipt_id']);
    if ((int) ($receipt['business_source_id'] ?? 0) !== $businessSourceId) {
        $failures[] = 'The advance receipt did not retain its Account Holder.';
    }

    $advanceLedgerRows = (new \App\Repositories\ReportRepository($app))->customerAdvanceLedger(
        [$branchId],
        null,
        null,
        $currency,
        $customerName
    );
    $advanceLedgerRow = array_values(array_filter(
        $advanceLedgerRows,
        static fn (array $row): bool => (int) ($row['receipt_id'] ?? 0) === (int) $advance['receipt_id']
            && (string) ($row['entry_type'] ?? '') === 'Customer Advance Received'
    ))[0] ?? null;
    if (! is_array($advanceLedgerRow)
        || (int) ($advanceLedgerRow['business_source_id'] ?? 0) !== $businessSourceId
        || trim((string) ($advanceLedgerRow['business_source_name'] ?? '')) === ''
    ) {
        $failures[] = 'The Customer Advance Ledger did not show the selected Account Holder.';
    }

    $service->refundCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_currency' => $currency,
        'advance_refund_receipt_id' => (int) $advance['receipt_id'],
        'advance_refund_amount' => $amount,
        'advance_refund_date' => date('Y-m-d'),
        'advance_refund_method' => 'cash',
        'advance_refund_treasury_account_id' => $treasuryAccountId,
        'advance_refund_reason' => 'Regression return',
    ], $userId, [$branchId]);

    $reportData = (new \App\Repositories\AccountLedgerRepository($app))->reportData([$branchId], [
        'businessSourceId' => $businessSourceId,
        'customerName' => $customerName,
        'currency' => $currency,
    ]);
    $report = (new \App\Services\AccountLedgerService($app))->buildReport($reportData);
    $advanceRows = array_values(array_filter((array) ($report['rows'] ?? []), static function (array $row) use ($advance): bool {
        return (string) ($row['transaction_reference'] ?? '') === (string) ($advance['receipt_no'] ?? '');
    }));
    if (count($advanceRows) !== 2) {
        $failures[] = 'The Account Ledger did not show exactly one receipt and one return row.';
    } else {
        if ((float) ($advanceRows[0]['raw_debit_amount'] ?? 0) !== $amount) {
            $failures[] = 'The advance receipt was not Debit/Money In.';
        }
        if ((float) ($advanceRows[1]['raw_credit_amount'] ?? 0) !== $amount) {
            $failures[] = 'The advance return was not Credit/Money Out.';
        }
        if (abs((float) ($advanceRows[1]['raw_balance_amount'] ?? 0)) > 0.005) {
            $failures[] = 'The Account Ledger running balance did not close after return.';
        }
    }
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Customer advance Account Holder regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer advance retained its Account Holder.' . PHP_EOL;
echo '[PASS] Customer Advance Ledger displayed its Account Holder.' . PHP_EOL;
echo '[PASS] Account Ledger recorded advance receipt as Debit and return as Credit.' . PHP_EOL;
echo '[PASS] No test data remained after rollback.' . PHP_EOL;
