<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\ReportRepository;
use App\Repositories\TreasuryRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

echo 'Cross-branch/cross-currency treasury journals (rollback only)' . PHP_EOL;
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new TreasuryRepository($app);
$reports = new ReportRepository($app);
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};
$accounts = $db->query(
    'SELECT id, branch_id, currency, account_code
     FROM treasury_accounts WHERE id IN (33, 34) AND is_active = 1'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byId = array_column($accounts, null, 'id');
$source = $byId[34] ?? null;
$destination = $byId[33] ?? null;
$actorId = (int) ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$check('UAE AED and Swat PKR cash accounts and actor exist', $source !== null && $destination !== null && $actorId > 0);

if ($failures === []) {
    $sourceBranch = (int) $source['branch_id'];
    $destinationBranch = (int) $destination['branch_id'];
    $date = date('Y-m-d');
    $balance = static fn (int $id): float => $treasury->currentBalanceForAccountId($id);
    $reportBalance = static function (array $account) use ($reports, $sourceBranch, $destinationBranch, $date): float {
        foreach ($reports->cashBankPosition([$sourceBranch, $destinationBranch], $date) as $row) {
            if ((int) $row['branch_id'] === (int) $account['branch_id']
                && (string) $row['currency'] === (string) $account['currency']
                && (string) $row['account_code'] === (string) $account['account_code']) {
                return (float) $row['balance'];
            }
        }
        return 0.0;
    };
    $sourceBefore = $balance(34);
    $destinationBefore = $balance(33);
    $sourceReportBefore = $reportBalance($source);
    $destinationReportBefore = $reportBalance($destination);
    $legacyBefore = (int) $db->query(
        'SELECT COUNT(*) FROM treasury_transactions
         WHERE id IN (5, 6, 9) AND status = "posted" AND journal_entry_id IS NULL'
    )->fetchColumn();
    $transferId = 0;

    $db->beginTransaction();
    try {
        $transferId = $treasury->saveTransfer([
            'branch_id' => $sourceBranch,
            'transaction_type' => 'cash_to_cash_transfer',
            'transaction_date' => $date,
            'source_currency' => 'AED',
            'destination_currency' => 'PKR',
            'from_treasury_account_id' => 34,
            'to_treasury_account_id' => 33,
            'source_amount' => 10.00,
            'destination_amount' => 770.00,
            'exchange_rate' => 77.00,
            'exchange_rate_effective_date' => $date,
            'reference_no' => 'REG-CROSS-TREASURY',
            'created_by_user_id' => $actorId,
        ], [$sourceBranch, $destinationBranch]);
        $statement = $db->prepare(
            'SELECT journal_entry_id, destination_journal_entry_id, status
             FROM treasury_transactions WHERE id = :id'
        );
        $statement->execute(['id' => $transferId]);
        $transfer = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $sourceJournalId = (int) ($transfer['journal_entry_id'] ?? 0);
        $destinationJournalId = (int) ($transfer['destination_journal_entry_id'] ?? 0);
        $check('Both distinct journal IDs are linked to the posted transfer',
            $sourceJournalId > 0 && $destinationJournalId > 0 && $sourceJournalId !== $destinationJournalId
                && ($transfer['status'] ?? '') === 'posted');
        $treasury->assertPostedTransferJournalIntegrity($transferId);
        $hideDestination = $db->prepare(
            'UPDATE treasury_transactions SET destination_journal_entry_id = NULL WHERE id = :id'
        );
        $hideDestination->execute(['id' => $transferId]);
        $missingJournalRejected = false;
        try {
            $treasury->assertPostedTransferJournalIntegrity($transferId);
        } catch (RuntimeException $exception) {
            $missingJournalRejected = str_contains($exception->getMessage(), 'incomplete journal links');
        } finally {
            $restoreDestination = $db->prepare(
                'UPDATE treasury_transactions SET destination_journal_entry_id = :journal_id WHERE id = :id'
            );
            $restoreDestination->execute(['journal_id' => $destinationJournalId, 'id' => $transferId]);
        }
        $check('Posted transfer journal guard rejects a missing destination link', $missingJournalRejected);

        $journalStatement = $db->prepare(
            'SELECT je.id, je.branch_id, je.currency,
                    ROUND(SUM(jel.debit_amount), 2) AS debit,
                    ROUND(SUM(jel.credit_amount), 2) AS credit
             FROM journal_entries je
             JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE je.id IN (:source_id, :destination_id)
             GROUP BY je.id, je.branch_id, je.currency'
        );
        $journalStatement->execute(['source_id' => $sourceJournalId, 'destination_id' => $destinationJournalId]);
        $journals = array_column($journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [], null, 'id');
        $sourceJournal = $journals[$sourceJournalId] ?? [];
        $destinationJournal = $journals[$destinationJournalId] ?? [];
        $check('Source AED and destination PKR journals balance in their own branches',
            (int) ($sourceJournal['branch_id'] ?? 0) === $sourceBranch
                && ($sourceJournal['currency'] ?? '') === 'AED'
                && abs((float) ($sourceJournal['debit'] ?? 0) - 10.00) <= 0.005
                && abs((float) ($sourceJournal['credit'] ?? 0) - 10.00) <= 0.005
                && (int) ($destinationJournal['branch_id'] ?? 0) === $destinationBranch
                && ($destinationJournal['currency'] ?? '') === 'PKR'
                && abs((float) ($destinationJournal['debit'] ?? 0) - 770.00) <= 0.005
                && abs((float) ($destinationJournal['credit'] ?? 0) - 770.00) <= 0.005);
        $check('Treasury and report balances both move by the original currency amounts',
            abs(($balance(34) - $sourceBefore) + 10.00) <= 0.005
                && abs(($balance(33) - $destinationBefore) - 770.00) <= 0.005
                && abs(($reportBalance($source) - $sourceReportBefore) + 10.00) <= 0.005
                && abs(($reportBalance($destination) - $destinationReportBefore) - 770.00) <= 0.005);

        $unauthorizedRejected = false;
        try {
            $treasury->voidTransfer($transferId, 'Unauthorized one-branch reversal probe', $actorId, [$sourceBranch]);
        } catch (RuntimeException $exception) {
            $unauthorizedRejected = str_contains($exception->getMessage(), 'do not have access');
        }
        $check('One-branch access cannot reverse a two-branch transfer',
            $unauthorizedRejected && abs(($balance(34) - $sourceBefore) + 10.00) <= 0.005);

        if ($legacyBefore === 3) {
            $legacyRejected = false;
            try {
                $treasury->voidTransfer(5, 'Unverified historical transfer probe', $actorId, [$sourceBranch, $destinationBranch]);
            } catch (RuntimeException $exception) {
                $legacyRejected = str_contains($exception->getMessage(), 'lacks a verified journal pair');
            }
            $check('Unverified historical transfer refuses automatic reversal', $legacyRejected);
        } else {
            $treasury->assertPostedTransferJournalIntegrity(5);
            $check('Reconciled historical transfer has both verified journal legs', $legacyBefore === 0);
        }

        $void = $treasury->voidTransfer($transferId, 'Rollback-only cross-dimension reversal', $actorId, [$sourceBranch, $destinationBranch]);
        $check('Voiding reverses both journal legs and restores both cash balances',
            (int) ($void['reversal_journal_entry_id'] ?? 0) > 0
                && (int) ($void['destination_reversal_journal_entry_id'] ?? 0) > 0
                && abs($balance(34) - $sourceBefore) <= 0.005
                && abs($balance(33) - $destinationBefore) <= 0.005
                && abs($reportBalance($source) - $sourceReportBefore) <= 0.005
                && abs($reportBalance($destination) - $destinationReportBefore) <= 0.005);

        $correctionInput = [
            'branch_id' => $sourceBranch,
            'transaction_type' => 'cash_to_cash_transfer',
            'transaction_date' => $date,
            'source_currency' => 'AED',
            'destination_currency' => 'PKR',
            'from_treasury_account_id' => 34,
            'to_treasury_account_id' => 33,
            'source_amount' => 5.00,
            'destination_amount' => 385.00,
            'exchange_rate' => 77.00,
            'exchange_rate_effective_date' => $date,
            'reference_no' => 'REG-CROSS-CORRECTION',
            'created_by_user_id' => $actorId,
        ];
        $toCorrectId = $treasury->saveTransfer($correctionInput, [$sourceBranch, $destinationBranch]);
        $corrected = $treasury->correctTransfer(array_merge($correctionInput, [
            'correction_transaction_id' => $toCorrectId,
            'correction_reason' => 'Rollback-only amount correction',
            'source_amount' => 7.00,
            'destination_amount' => 539.00,
        ]), $actorId, [$sourceBranch, $destinationBranch]);
        $correctedId = (int) ($corrected['corrected_treasury_transaction_id'] ?? 0);
        $correctionStatement = $db->prepare(
            'SELECT id, status, journal_entry_id, destination_journal_entry_id,
                    reversal_journal_entry_id, destination_reversal_journal_entry_id
             FROM treasury_transactions WHERE id IN (:old_id, :new_id)'
        );
        $correctionStatement->execute(['old_id' => $toCorrectId, 'new_id' => $correctedId]);
        $correctionRows = array_column($correctionStatement->fetchAll(PDO::FETCH_ASSOC) ?: [], null, 'id');
        $oldCorrection = $correctionRows[$toCorrectId] ?? [];
        $newCorrection = $correctionRows[$correctedId] ?? [];
        $check('Correction reverses both old legs and posts both new legs',
            ($oldCorrection['status'] ?? '') === 'void'
                && (int) ($oldCorrection['reversal_journal_entry_id'] ?? 0) > 0
                && (int) ($oldCorrection['destination_reversal_journal_entry_id'] ?? 0) > 0
                && ($newCorrection['status'] ?? '') === 'posted'
                && (int) ($newCorrection['journal_entry_id'] ?? 0) > 0
                && (int) ($newCorrection['destination_journal_entry_id'] ?? 0) > 0
                && abs(($balance(34) - $sourceBefore) + 7.00) <= 0.005
                && abs(($balance(33) - $destinationBefore) - 539.00) <= 0.005);
        $treasury->voidTransfer($correctedId, 'Rollback-only corrected transfer void', $actorId, [$sourceBranch, $destinationBranch]);
        $check('Corrected transfer void restores both original cash balances',
            abs($balance(34) - $sourceBefore) <= 0.005
                && abs($balance(33) - $destinationBefore) <= 0.005);

        $db->exec('SAVEPOINT negative_cross_currency_transfer');
        $zeroSource = $db->prepare(
            'UPDATE treasury_accounts SET opening_balance = COALESCE(opening_balance, 0) - :balance WHERE id = :id'
        );
        $zeroSource->execute(['balance' => $balance(34), 'id' => 34]);
        $check('Rollback-only AED source starts at zero', abs($balance(34)) <= 0.005);
        $negativeTransferId = $treasury->saveTransfer([
            'branch_id' => $sourceBranch,
            'transaction_type' => 'cash_to_cash_transfer',
            'transaction_date' => $date,
            'source_currency' => 'AED',
            'destination_currency' => 'PKR',
            'from_treasury_account_id' => 34,
            'to_treasury_account_id' => 33,
            'source_amount' => 10.00,
            'destination_amount' => 770.00,
            'exchange_rate' => 77.00,
            'exchange_rate_effective_date' => $date,
            'reference_no' => 'REG-NEGATIVE-CROSS-TREASURY',
            'created_by_user_id' => $actorId,
        ], [$sourceBranch, $destinationBranch]);
        $treasury->assertPostedTransferJournalIntegrity($negativeTransferId);
        $check('Cross-currency transfer from zero records AED -10 and PKR +770',
            abs($balance(34) + 10.00) <= 0.005
                && abs($balance(33) - $destinationBefore - 770.00) <= 0.005);
        $treasury->voidTransfer($negativeTransferId, 'Rollback-only negative cross-currency reversal', $actorId, [$sourceBranch, $destinationBranch]);
        $check('Reversing negative cross-currency transfer restores both accounts',
            abs($balance(34)) <= 0.005
                && abs($balance(33) - $destinationBefore) <= 0.005);
        $db->exec('ROLLBACK TO SAVEPOINT negative_cross_currency_transfer');
        $db->exec('RELEASE SAVEPOINT negative_cross_currency_transfer');
        $check('Cross-currency probe restores its source opening balance',
            abs($balance(34) - $sourceBefore) <= 0.005);

        $check('Historical transfer journal state is unchanged by the rollback-only test',
            (int) $db->query(
                'SELECT COUNT(*) FROM treasury_transactions
                 WHERE id IN (5, 6, 9) AND status = "posted" AND journal_entry_id IS NULL'
            )->fetchColumn() === $legacyBefore);
    } catch (Throwable $exception) {
        $check('Cross-dimension transfer completed: ' . $exception->getMessage(), false);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $statement = $db->prepare('SELECT COUNT(*) FROM treasury_transactions WHERE id = :id');
    $statement->execute(['id' => $transferId]);
    $check('Rollback retained no test transfer or account movement',
        (int) $statement->fetchColumn() === 0
            && abs($balance(34) - $sourceBefore) <= 0.005
            && abs($balance(33) - $destinationBefore) <= 0.005);
}

if ($failures !== []) {
    exit(1);
}
echo 'Cross-dimension treasury regression passed; no test data was retained.' . PHP_EOL;
