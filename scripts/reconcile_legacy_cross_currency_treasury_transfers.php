<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\ReportRepository;
use App\Repositories\TreasuryRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$usage = "Usage: php scripts/reconcile_legacy_cross_currency_treasury_transfers.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);
if ($apply && $rehearse) {
    fwrite(STDERR, $usage);
    exit(1);
}

$expected = [
    5 => ['date' => '2026-08-28', 'source' => 100000, 'destination' => 7700000],
    6 => ['date' => '2026-08-31', 'source' => 150000, 'destination' => 11550000],
    9 => ['date' => '2026-09-04', 'source' => 50000, 'destination' => 3850000],
];
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$accounting = new AccountingRepository($app);
$treasury = new TreasuryRepository($app);
$reports = new ReportRepository($app);
$query = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$near = static fn (mixed $actual, float $value): bool => abs((float) $actual - $value) < 0.005;
$position = static function (array $account) use ($reports): float {
    foreach ($reports->cashBankPosition([(int) $account['branch_id']], '9999-12-31', (string) $account['currency']) as $row) {
        if ((int) $row['branch_id'] === (int) $account['branch_id']
            && $row['currency'] === $account['currency']
            && $row['account_code'] === $account['account_code']) {
            return round((float) $row['balance'], 2);
        }
    }
    throw new RuntimeException('Cash/bank position is missing treasury account #' . $account['id']);
};

$db->beginTransaction();
try {
    $transfers = $query(
        'SELECT id, branch_id, source_branch_id, destination_branch_id, transaction_type,
                transaction_date, status, currency, source_currency, destination_currency,
                from_treasury_account_id, to_treasury_account_id, amount, source_amount,
                destination_amount, exchange_rate, exchange_rate_effective_date,
                journal_entry_id, destination_journal_entry_id, reversal_journal_entry_id,
                destination_reversal_journal_entry_id
         FROM treasury_transactions WHERE id IN (5, 6, 9) ORDER BY id FOR UPDATE'
    );
    $accounts = $query(
        'SELECT id, branch_id, account_code, account_name, linked_account_id, currency,
                account_type, is_active, opening_balance
         FROM treasury_accounts WHERE id IN (33, 34) ORDER BY id FOR UPDATE'
    );
    $clearingAccounts = $query(
        'SELECT id, code FROM chart_of_accounts
         WHERE code IN ("INTERBRANCH_TRANSFER_RECEIVABLE", "INTERBRANCH_TRANSFER_PAYABLE")
         ORDER BY code'
    );
    $check(count($transfers) === 3 && count($accounts) === 2, 'Reviewed transfer or treasury account is missing.');
    $clearingCodes = array_column($clearingAccounts, 'code');
    $check($clearingCodes === [] || $clearingCodes === [
        'INTERBRANCH_TRANSFER_PAYABLE', 'INTERBRANCH_TRANSFER_RECEIVABLE',
    ], 'Interbranch clearing accounts are incomplete; inspect them before journal restoration.');
    $byAccount = array_column($accounts, null, 'id');
    $sourceAccount = $byAccount[34] ?? [];
    $destinationAccount = $byAccount[33] ?? [];
    $check((int) ($sourceAccount['branch_id'] ?? 0) === 2
        && $sourceAccount['currency'] === 'AED' && $sourceAccount['account_type'] === 'cash'
        && (int) $sourceAccount['is_active'] === 1 && (int) $sourceAccount['linked_account_id'] > 0
        && (int) ($destinationAccount['branch_id'] ?? 0) === 1
        && $destinationAccount['currency'] === 'PKR' && $destinationAccount['account_type'] === 'cash'
        && (int) $destinationAccount['is_active'] === 1 && (int) $destinationAccount['linked_account_id'] > 0,
        'Source or destination account identity changed.');

    $linkedCount = 0;
    foreach ($transfers as $transfer) {
        $id = (int) $transfer['id'];
        $truth = $expected[$id] ?? null;
        $check($truth !== null && $transfer['transaction_type'] === 'cash_to_cash_transfer'
            && $transfer['status'] === 'posted'
            && $transfer['transaction_date'] === $truth['date']
            && $transfer['exchange_rate_effective_date'] === $truth['date']
            && (int) $transfer['branch_id'] === 2
            && (int) $transfer['source_branch_id'] === 2
            && (int) $transfer['destination_branch_id'] === 1
            && (int) $transfer['from_treasury_account_id'] === 34
            && (int) $transfer['to_treasury_account_id'] === 33
            && $transfer['currency'] === 'AED'
            && $transfer['source_currency'] === 'AED'
            && $transfer['destination_currency'] === 'PKR'
            && $near($transfer['amount'], $truth['source'])
            && $near($transfer['source_amount'], $truth['source'])
            && $near($transfer['destination_amount'], $truth['destination'])
            && $near($transfer['exchange_rate'], 77)
            && $transfer['reversal_journal_entry_id'] === null
            && $transfer['destination_reversal_journal_entry_id'] === null,
            'Transfer #' . $id . ' differs from the reviewed financial facts.');
        $sourceLinked = (int) ($transfer['journal_entry_id'] ?? 0) > 0;
        $destinationLinked = (int) ($transfer['destination_journal_entry_id'] ?? 0) > 0;
        $check($sourceLinked === $destinationLinked, 'Transfer #' . $id . ' has only one journal leg linked.');
        $linkedCount += (int) $sourceLinked;
    }
    $check($linkedCount === 0 || $linkedCount === 3, 'Only some reviewed transfers are journaled.');

    $references = $query(
        'SELECT id, source_reference FROM journal_entries
         WHERE source_type = "treasury_transfer_posted"
           AND source_reference IN (
               "TR-5-SOURCE", "TR-5-DESTINATION", "TR-6-SOURCE",
               "TR-6-DESTINATION", "TR-9-SOURCE", "TR-9-DESTINATION"
           ) ORDER BY id'
    );
    $check(count($references) === $linkedCount * 2, 'Duplicate or unlinked transfer journal reference exists.');
    if ($linkedCount === 3) {
        foreach ($transfers as $transfer) {
            $treasury->assertPostedTransferJournalIntegrity((int) $transfer['id']);
        }
        $check($near($treasury->currentBalanceForAccountId(34), $position($sourceAccount))
            && $near($treasury->currentBalanceForAccountId(33), $position($destinationAccount)),
            'Existing repaired journals do not reconcile both cash accounts.');
        $db->rollBack();
        echo "ALREADY APPLIED: transfers #5, #6, and #9 have verified AED and PKR journal pairs.\n";
        exit(0);
    }

    $unlinked = array_map('intval', array_column($query(
        'SELECT id FROM treasury_transactions
         WHERE status = "posted" AND journal_entry_id IS NULL
           AND transaction_type IN (
               "cash_deposit_to_bank", "bank_withdrawal_to_cash",
               "bank_to_bank_transfer", "cash_to_cash_transfer"
           ) ORDER BY id'
    ), 'id'));
    $check($unlinked === [5, 6, 9], 'Other posted transfers lack journals; inspect them before this repair.');

    $sourceBalanceBefore = $treasury->currentBalanceForAccountId(34);
    $destinationBalanceBefore = $treasury->currentBalanceForAccountId(33);
    $sourcePositionBefore = $position($sourceAccount);
    $destinationPositionBefore = $position($destinationAccount);
    $check($near($sourceBalanceBefore - $sourcePositionBefore, -300000)
        && $near($destinationBalanceBefore - $destinationPositionBefore, 23100000),
        'The live-balance and journal-position differences are not the reviewed transfer totals.');

    $snapshot = compact('transfers', 'accounts', 'clearingAccounts', 'unlinked', 'sourceBalanceBefore',
        'destinationBalanceBefore', 'sourcePositionBefore', 'destinationPositionBefore');
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'fingerprint' => $fingerprint,
        'transfer_ids' => [5, 6, 9],
        'source' => ['account' => $sourceAccount['account_name'], 'currency' => 'AED', 'journal_out_missing' => 300000,
            'live_balance_before_and_after' => $sourceBalanceBefore,
            'journal_position_before' => $sourcePositionBefore, 'journal_position_after' => $sourceBalanceBefore],
        'destination' => ['account' => $destinationAccount['account_name'], 'currency' => 'PKR', 'journal_in_missing' => 23100000,
            'live_balance_before_and_after' => $destinationBalanceBefore,
            'journal_position_before' => $destinationPositionBefore, 'journal_position_after' => $destinationBalanceBefore],
        'customer_or_supplier_records_changed' => false,
    ];
    if (!$apply && !$rehearse) {
        $db->rollBack();
        echo json_encode(['mode' => 'preview', 'inspection_only' => true] + $plan,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    $actor = (int) ($options['actor'] ?? 0);
    $check($actor > 0 && $query(
        'SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.is_active = 1 AND r.code = "super_admin"', [$actor]
    ) !== []
        && hash_equals($fingerprint, strtolower((string) ($options['fingerprint'] ?? ''))),
        'An active Super Admin actor and the exact current preview fingerprint are required.');

    $journalIds = [];
    foreach ($transfers as $transfer) {
        $id = (int) $transfer['id'];
        [$sourceJournalId, $destinationJournalId] = $accounting->postCrossDimensionTreasuryTransfer([
            'treasury_transaction_id' => $id,
            'source_branch_id' => 2,
            'destination_branch_id' => 1,
            'source_currency' => 'AED',
            'destination_currency' => 'PKR',
            'source_amount' => (float) $transfer['source_amount'],
            'destination_amount' => (float) $transfer['destination_amount'],
            'from_treasury_account_id' => 34,
            'to_treasury_account_id' => 33,
            'entry_date' => $transfer['transaction_date'],
            'actor_user_id' => $actor,
            'source_narration' => 'Historical journal restoration for transfer #' . $id . '; no new money moved',
            'destination_narration' => 'Historical journal restoration for transfer #' . $id . '; no new money moved',
            'source_line_description' => 'Original AED cash out for historical transfer #' . $id,
            'destination_line_description' => 'Original PKR cash in for historical transfer #' . $id,
        ]);
        $link = $db->prepare(
            'UPDATE treasury_transactions
             SET journal_entry_id = :source_id, destination_journal_entry_id = :destination_id
             WHERE id = :id AND status = "posted"
               AND journal_entry_id IS NULL AND destination_journal_entry_id IS NULL'
        );
        $link->execute(['source_id' => $sourceJournalId, 'destination_id' => $destinationJournalId, 'id' => $id]);
        $check($link->rowCount() === 1, 'Transfer #' . $id . ' could not be linked atomically.');
        $treasury->assertPostedTransferJournalIntegrity($id);
        AuditLog::record($app, 'treasury.transfer.legacy_journals_restored', [
            'user_id' => $actor,
            'treasury_transaction_id' => $id,
            'original_transfer_date' => $transfer['transaction_date'],
            'source_journal_entry_id' => $sourceJournalId,
            'destination_journal_entry_id' => $destinationJournalId,
            'no_new_money_moved' => true,
        ]);
        $journalIds[$id] = [$sourceJournalId, $destinationJournalId];
    }

    $check($near($treasury->currentBalanceForAccountId(34), $sourceBalanceBefore)
        && $near($treasury->currentBalanceForAccountId(33), $destinationBalanceBefore),
        'Live cash balance changed; refusing to commit the journal restoration.');
    $check($near($position($sourceAccount), $sourceBalanceBefore)
        && $near($position($destinationAccount), $destinationBalanceBefore),
        'Journal-based cash positions do not equal the unchanged live balances.');
    foreach ($transfers as $transfer) {
        $id = (int) $transfer['id'];
        $date = $transfer['transaction_date'];
        $sourceRows = array_values(array_filter(
            $reports->cashBankLedger([2], $date, $date, 'AED', 'transfer', null, 34),
            static fn (array $row): bool => $row['reference'] === 'TR-' . $id . '-SOURCE'
        ));
        $destinationRows = array_values(array_filter(
            $reports->cashBankLedger([1], $date, $date, 'PKR', 'transfer', null, 33),
            static fn (array $row): bool => $row['reference'] === 'TR-' . $id . '-DESTINATION'
        ));
        $check(count($sourceRows) === 1 && count($destinationRows) === 1
            && $near($sourceRows[0]['credit_amount'], (float) $transfer['source_amount'])
            && $near($destinationRows[0]['debit_amount'], (float) $transfer['destination_amount']),
            'Detailed cash ledger does not show both legs of transfer #' . $id . ' exactly once.');
    }
    $check($reports->unjournaledTreasuryTransferCount([1, 2]) === 0,
        'A posted unjournaled treasury transfer remains.');

    $mode = $apply ? 'applied' : 'rehearsed-and-rolled-back';
    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $mode, 'journal_ids' => $journalIds] + $plan,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
