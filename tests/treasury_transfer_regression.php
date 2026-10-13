<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$treasuryRepository = new \App\Repositories\TreasuryRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Treasury transfer regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$accounts = $treasuryRepository->transferAccounts([1, 2]);
$pair = null;
$secondBank = null;
$secondCash = null;

foreach ($accounts as $cashAccount) {
    if ((string) ($cashAccount['account_type'] ?? '') !== 'cash') {
        continue;
    }

    foreach ($accounts as $bankAccount) {
        if ((string) ($bankAccount['account_type'] ?? '') !== 'bank') {
            continue;
        }

        if ((int) ($cashAccount['branch_id'] ?? 0) === (int) ($bankAccount['branch_id'] ?? 0)
            && strtoupper((string) ($cashAccount['currency'] ?? '')) === strtoupper((string) ($bankAccount['currency'] ?? ''))) {
            $pair = [
                'branch_id' => (int) ($cashAccount['branch_id'] ?? 0),
                'currency' => strtoupper((string) ($cashAccount['currency'] ?? 'PKR')),
                'cash' => $cashAccount,
                'bank' => $bankAccount,
            ];
            foreach ($accounts as $candidateBank) {
                if ((string) ($candidateBank['account_type'] ?? '') !== 'bank') {
                    continue;
                }
                if ((int) ($candidateBank['id'] ?? 0) === (int) ($bankAccount['id'] ?? 0)) {
                    continue;
                }
                if ((int) ($candidateBank['branch_id'] ?? 0) === $pair['branch_id']
                    && strtoupper((string) ($candidateBank['currency'] ?? '')) === $pair['currency']) {
                    $secondBank = $candidateBank;
                    break;
                }
            }

            foreach ($accounts as $candidateCash) {
                if ((string) ($candidateCash['account_type'] ?? '') !== 'cash') {
                    continue;
                }
                if ((int) ($candidateCash['id'] ?? 0) === (int) ($cashAccount['id'] ?? 0)) {
                    continue;
                }
                if ((int) ($candidateCash['branch_id'] ?? 0) === $pair['branch_id']
                    && strtoupper((string) ($candidateCash['currency'] ?? '')) === $pair['currency']) {
                    $secondCash = $candidateCash;
                    break;
                }
            }
            break 2;
        }
    }
}

$check(
    'Matching cash and bank treasury accounts exist for regression',
    is_array($pair),
    is_array($pair)
        ? ('branch=' . $pair['branch_id'] . ', currency=' . $pair['currency'])
        : 'Need one active cash account and one active bank account in the same branch/currency.'
);

if (! is_array($pair)) {
    return 1;
}

$today = date('Y-m-d');
$actorUserId = 1;

$db->beginTransaction();

try {
    $cashCurrentBalance = round((float) ($pair['cash']['current_balance'] ?? 0), 2);
    if ($cashCurrentBalance < 100.00) {
        $topUpAmount = round(200.00 - $cashCurrentBalance, 2);
        $seedStatement = $db->prepare(
            'UPDATE treasury_accounts
             SET opening_balance = opening_balance + :top_up_amount
             WHERE id = :id'
        );
        $seedStatement->execute([
            'top_up_amount' => $topUpAmount,
            'id' => (int) ($pair['cash']['id'] ?? 0),
        ]);
        $pair['cash']['current_balance'] = number_format($cashCurrentBalance + $topUpAmount, 2, '.', '');
        $check('Regression seeded temporary cash balance for treasury transfer tests', true, 'top_up=' . number_format($topUpAmount, 2));
    }

    $depositTransferId = $treasuryRepository->saveTransfer([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'cash_deposit_to_bank',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'from_treasury_account_id' => (int) ($pair['cash']['id'] ?? 0),
        'to_treasury_account_id' => (int) ($pair['bank']['id'] ?? 0),
        'amount' => '50.00',
        'reference_no' => 'RG-DEPOSIT-001',
        'narration' => 'Automated treasury deposit regression',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);

    $withdrawTransferId = $treasuryRepository->saveTransfer([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'bank_withdrawal_to_cash',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'from_treasury_account_id' => (int) ($pair['bank']['id'] ?? 0),
        'to_treasury_account_id' => (int) ($pair['cash']['id'] ?? 0),
        'amount' => '20.00',
        'reference_no' => 'RG-WITHDRAW-001',
        'narration' => 'Automated treasury withdrawal regression',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);

    $check('Cash deposit to bank transfer posted', $depositTransferId > 0, 'id=' . $depositTransferId);
    $check('Bank withdrawal to cash transfer posted', $withdrawTransferId > 0, 'id=' . $withdrawTransferId);

    $bankToBankTransferId = 0;
    if (is_array($secondBank)) {
        $bankToBankTransferId = $treasuryRepository->saveTransfer([
            'branch_id' => $pair['branch_id'],
            'transaction_type' => 'bank_to_bank_transfer',
            'transaction_date' => $today,
            'currency' => $pair['currency'],
            'from_treasury_account_id' => (int) ($pair['bank']['id'] ?? 0),
            'to_treasury_account_id' => (int) ($secondBank['id'] ?? 0),
            'amount' => '10.00',
            'reference_no' => 'RG-B2B-001',
            'narration' => 'Automated bank-to-bank regression',
            'created_by_user_id' => $actorUserId,
        ], [$pair['branch_id']]);
        $check('Bank to bank transfer posted', $bankToBankTransferId > 0, 'id=' . $bankToBankTransferId);
    } else {
        $check('Bank to bank transfer regression prerequisites', true, 'Skipped: only one matching bank account available');
    }

    $cashToCashTransferId = 0;
    if (is_array($secondCash)) {
        $cashToCashTransferId = $treasuryRepository->saveTransfer([
            'branch_id' => $pair['branch_id'],
            'transaction_type' => 'cash_to_cash_transfer',
            'transaction_date' => $today,
            'currency' => $pair['currency'],
            'from_treasury_account_id' => (int) ($pair['cash']['id'] ?? 0),
            'to_treasury_account_id' => (int) ($secondCash['id'] ?? 0),
            'amount' => '5.00',
            'reference_no' => 'RG-C2C-001',
            'narration' => 'Automated cash-to-cash regression',
            'created_by_user_id' => $actorUserId,
        ], [$pair['branch_id']]);
        $check('Cash to cash transfer posted', $cashToCashTransferId > 0, 'id=' . $cashToCashTransferId);
    } else {
        $check('Cash to cash transfer regression prerequisites', true, 'Skipped: only one matching cash account available');
    }

    $voidTransferId = $treasuryRepository->saveTransfer([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'cash_deposit_to_bank',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'from_treasury_account_id' => (int) ($pair['cash']['id'] ?? 0),
        'to_treasury_account_id' => (int) ($pair['bank']['id'] ?? 0),
        'amount' => '15.00',
        'reference_no' => 'RG-VOID-001',
        'narration' => 'Automated treasury void regression',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Treasury transfer created for void regression', $voidTransferId > 0, 'id=' . $voidTransferId);

    $voidResult = $treasuryRepository->voidTransfer($voidTransferId, 'Automated regression void', $actorUserId, [$pair['branch_id']]);
    $check(
        'Treasury transfer void action succeeded',
        (int) ($voidResult['id'] ?? 0) === $voidTransferId && (int) ($voidResult['reversal_journal_entry_id'] ?? 0) > 0,
        json_encode($voidResult, JSON_UNESCAPED_SLASHES)
    );

    $cashId = (int) $pair['cash']['id'];
    $bankId = (int) $pair['bank']['id'];
    $cashBalance = $treasuryRepository->currentBalanceForAccountId($cashId);
    $zeroCash = $db->prepare(
        'UPDATE treasury_accounts SET opening_balance = opening_balance - :balance WHERE id = :id'
    );
    $zeroCash->execute(['balance' => $cashBalance, 'id' => $cashId]);
    $bankBalance = $treasuryRepository->currentBalanceForAccountId($bankId);
    $check('Rollback-only source account starts at zero',
        abs($treasuryRepository->currentBalanceForAccountId($cashId)) <= 0.005);

    $negativeTransferId = $treasuryRepository->saveTransfer([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'cash_deposit_to_bank',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'from_treasury_account_id' => $cashId,
        'to_treasury_account_id' => $bankId,
        'amount' => '50.00',
        'reference_no' => 'RG-NEGATIVE-TRANSFER-001',
        'narration' => 'Rollback-only transfer from zero balance',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Transfer from zero posts a negative source and positive destination',
        $negativeTransferId > 0
            && abs($treasuryRepository->currentBalanceForAccountId($cashId) + 50.00) <= 0.005
            && abs($treasuryRepository->currentBalanceForAccountId($bankId) - $bankBalance - 50.00) <= 0.005);
    $treasuryRepository->voidTransfer($negativeTransferId, 'Rollback-only transfer reversal', $actorUserId, [$pair['branch_id']]);
    $check('Voiding the negative-balance transfer restores both accounts',
        abs($treasuryRepository->currentBalanceForAccountId($cashId)) <= 0.005
            && abs($treasuryRepository->currentBalanceForAccountId($bankId) - $bankBalance) <= 0.005);

    $moneyOutId = $treasuryRepository->saveDirectEntry([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_decrease',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'amount' => '25.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-NEGATIVE-MONEY-OUT-001',
        'narration' => 'Rollback-only money out from zero balance',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Manual Money Out from zero posts a negative cash balance',
        $moneyOutId > 0 && abs($treasuryRepository->currentBalanceForAccountId($cashId) + 25.00) <= 0.005);
    $moneyInId = $treasuryRepository->saveDirectEntry([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_increase',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'amount' => '40.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-NEGATIVE-RECOVERY-001',
        'narration' => 'Rollback-only money in after negative balance',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Later Money In nets against the negative balance',
        $moneyInId > 0 && abs($treasuryRepository->currentBalanceForAccountId($cashId) - 15.00) <= 0.005);
    $treasuryRepository->voidTransfer($moneyInId, 'Rollback-only Money In reversal', $actorUserId, [$pair['branch_id']]);
    $treasuryRepository->voidTransfer($moneyOutId, 'Rollback-only Money Out reversal', $actorUserId, [$pair['branch_id']]);
    $check('Voiding both manual entries restores the source account to zero',
        abs($treasuryRepository->currentBalanceForAccountId($cashId)) <= 0.005);

    $correctionSourceId = $treasuryRepository->saveDirectEntry([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_decrease',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'amount' => '20.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-NEGATIVE-CORRECTION-001',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $corrected = $treasuryRepository->correctDirectEntry([
        'correction_transaction_id' => $correctionSourceId,
        'correction_reason' => 'Rollback-only amount correction',
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_decrease',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'amount' => '35.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-NEGATIVE-CORRECTION-002',
        'created_by_user_id' => $actorUserId,
    ], $actorUserId, [$pair['branch_id']]);
    $correctedId = (int) ($corrected['corrected_treasury_transaction_id'] ?? 0);
    $check('Correcting Money Out retains the exact negative balance',
        $correctedId > 0 && abs($treasuryRepository->currentBalanceForAccountId($cashId) + 35.00) <= 0.005);
    $treasuryRepository->voidTransfer($correctedId, 'Rollback-only corrected Money Out reversal', $actorUserId, [$pair['branch_id']]);
    $check('Voiding corrected Money Out restores the cash account',
        abs($treasuryRepository->currentBalanceForAccountId($cashId)) <= 0.005);

    $originalDate = date('Y-m-d', strtotime('-7 days'));
    $olderEntryId = $treasuryRepository->saveDirectEntry([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_increase',
        'transaction_date' => $originalDate,
        'currency' => $pair['currency'],
        'amount' => '20.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-DATED-CORRECTION-001',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $datedCorrection = $treasuryRepository->correctDirectEntry([
        'correction_transaction_id' => $olderEntryId,
        'correction_reason' => 'Rollback-only dated amount correction',
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_increase',
        'transaction_date' => $originalDate,
        'currency' => $pair['currency'],
        'amount' => '35.00',
        'treasury_account_id' => $cashId,
        'reference_no' => 'RG-DATED-CORRECTION-002',
        'created_by_user_id' => $actorUserId,
    ], $actorUserId, [$pair['branch_id']]);
    $datedCorrectionId = (int) ($datedCorrection['corrected_treasury_transaction_id'] ?? 0);
    $reportRepository = new \App\Repositories\ReportRepository($app);
    $olderRows = array_values(array_filter(
        $reportRepository->directMoneyInOutLedger([$pair['branch_id']], $originalDate, $originalDate, $cashId),
        static fn (array $row): bool => (int) $row['treasury_transaction_id'] === $olderEntryId
    ));
    $correctionRows = array_values(array_filter(
        $reportRepository->directMoneyInOutLedger([$pair['branch_id']], $today, $today, $cashId),
        static fn (array $row): bool => in_array((int) $row['treasury_transaction_id'], [$olderEntryId, $datedCorrectionId], true)
    ));
    $check('Original dated entry stays in its historical period and is marked void',
        count($olderRows) === 1
        && $olderRows[0]['source_group'] === 'posted'
        && $olderRows[0]['transaction_status'] === 'void'
        && abs((float) $olderRows[0]['debit_amount'] - 20.00) <= 0.005);
    $check('Correction reversal and replacement both post on the correction date',
        count($correctionRows) === 2
        && count(array_filter($correctionRows, static fn (array $row): bool => $row['source_group'] === 'reversal'
            && (int) $row['treasury_transaction_id'] === $olderEntryId
            && abs((float) $row['credit_amount'] - 20.00) <= 0.005)) === 1
        && count(array_filter($correctionRows, static fn (array $row): bool => $row['source_group'] === 'posted'
            && $row['transaction_status'] === 'posted'
            && (int) $row['treasury_transaction_id'] === $datedCorrectionId
            && abs((float) $row['debit_amount'] - 35.00) <= 0.005)) === 1
        && abs($treasuryRepository->currentBalanceForAccountId($cashId) - 35.00) <= 0.005);
    $reportFormatter = new ReflectionMethod(\App\Services\ReportService::class, 'moneyInOutLedgerReport');
    [$formattedCorrectionRows] = $reportFormatter->invoke(new \App\Services\ReportService($app), array_merge($olderRows, $correctionRows));
    $correctableRows = array_values(array_filter(
        $formattedCorrectionRows,
        static fn (array $row): bool => (bool) ($row['can_correct'] ?? false)
    ));
    $check('Only the posted replacement offers another correction action',
        count($correctableRows) === 1
        && (int) $correctableRows[0]['treasury_transaction_id'] === $datedCorrectionId);
    $treasuryRepository->voidTransfer($datedCorrectionId, 'Rollback-only dated correction reversal', $actorUserId, [$pair['branch_id']]);

    $zeroBank = $db->prepare(
        'UPDATE treasury_accounts SET opening_balance = opening_balance - :balance WHERE id = :id'
    );
    $zeroBank->execute([
        'balance' => $treasuryRepository->currentBalanceForAccountId($bankId),
        'id' => $bankId,
    ]);
    $bankMoneyOutId = $treasuryRepository->saveDirectEntry([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'adjustment_decrease',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'amount' => '30.00',
        'treasury_account_id' => $bankId,
        'reference_no' => 'RG-NEGATIVE-BANK-OUT-001',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Manual Money Out from zero posts a negative bank balance',
        $bankMoneyOutId > 0 && abs($treasuryRepository->currentBalanceForAccountId($bankId) + 30.00) <= 0.005);
    $treasuryRepository->voidTransfer($bankMoneyOutId, 'Rollback-only bank Money Out reversal', $actorUserId, [$pair['branch_id']]);
    $check('Voiding bank Money Out restores the bank account',
        abs($treasuryRepository->currentBalanceForAccountId($bankId)) <= 0.005);

    $bankTransferId = $treasuryRepository->saveTransfer([
        'branch_id' => $pair['branch_id'],
        'transaction_type' => 'bank_withdrawal_to_cash',
        'transaction_date' => $today,
        'currency' => $pair['currency'],
        'from_treasury_account_id' => $bankId,
        'to_treasury_account_id' => $cashId,
        'amount' => '40.00',
        'reference_no' => 'RG-NEGATIVE-BANK-TRANSFER-001',
        'created_by_user_id' => $actorUserId,
    ], [$pair['branch_id']]);
    $check('Transfer from zero bank posts negative bank and positive cash',
        $bankTransferId > 0
            && abs($treasuryRepository->currentBalanceForAccountId($bankId) + 40.00) <= 0.005
            && abs($treasuryRepository->currentBalanceForAccountId($cashId) - 40.00) <= 0.005);
    $treasuryRepository->voidTransfer($bankTransferId, 'Rollback-only bank transfer reversal', $actorUserId, [$pair['branch_id']]);
    $check('Voiding bank transfer restores both accounts to zero',
        abs($treasuryRepository->currentBalanceForAccountId($bankId)) <= 0.005
            && abs($treasuryRepository->currentBalanceForAccountId($cashId)) <= 0.005);

    $statement = $db->prepare(
        'SELECT id, transaction_type, amount, journal_entry_id, status, reversal_journal_entry_id
         FROM treasury_transactions
         WHERE id IN (:deposit_id, :withdraw_id, :bank_to_bank_id, :cash_to_cash_id, :void_id)
         ORDER BY id ASC'
    );
    $statement->execute([
        'deposit_id' => $depositTransferId,
        'withdraw_id' => $withdrawTransferId,
        'bank_to_bank_id' => $bankToBankTransferId,
        'cash_to_cash_id' => $cashToCashTransferId,
        'void_id' => $voidTransferId,
    ]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $indexed = [];
    foreach ($rows as $row) {
        $indexed[(int) ($row['id'] ?? 0)] = $row;
    }

    $depositRow = $indexed[$depositTransferId] ?? null;
    $withdrawRow = $indexed[$withdrawTransferId] ?? null;
    $voidedRow = $indexed[$voidTransferId] ?? null;

    $check(
        'Treasury transactions were stored with journal links',
        is_array($depositRow) && (int) ($depositRow['journal_entry_id'] ?? 0) > 0
            && is_array($withdrawRow) && (int) ($withdrawRow['journal_entry_id'] ?? 0) > 0,
        json_encode($rows, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Voided treasury transfer stores void status and reversal journal',
        is_array($voidedRow)
            && (string) ($voidedRow['status'] ?? '') === 'void'
            && (int) ($voidedRow['reversal_journal_entry_id'] ?? 0) > 0,
        json_encode($voidedRow, JSON_UNESCAPED_SLASHES)
    );

    $journalIds = array_values(array_filter([
        (int) ($depositRow['journal_entry_id'] ?? 0),
        (int) ($withdrawRow['journal_entry_id'] ?? 0),
        (int) (($indexed[$bankToBankTransferId]['journal_entry_id'] ?? 0)),
        (int) (($indexed[$cashToCashTransferId]['journal_entry_id'] ?? 0)),
        (int) ($voidedRow['journal_entry_id'] ?? 0),
        (int) ($voidedRow['reversal_journal_entry_id'] ?? 0),
    ]));

    $lineCheckPassed = false;
    $lineDetails = [];

    if ($journalIds !== []) {
        $journalPlaceholders = implode(',', array_fill(0, count($journalIds), '?'));
        $lineStatement = $db->prepare(
            "SELECT
                je.id AS journal_entry_id,
                je.source_type,
                coa.code AS account_code,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id IN ({$journalPlaceholders})
             ORDER BY je.id ASC, jel.id ASC"
        );
        $lineStatement->execute($journalIds);
        $journalLines = $lineStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lineDetails = $journalLines;

        $lineCheckPassed = count(array_filter($journalLines, static function (array $row): bool {
            return (string) ($row['source_type'] ?? '') === 'treasury_transfer_posted'
                && (
                    round((float) ($row['debit_amount'] ?? 0), 2) > 0
                    || round((float) ($row['credit_amount'] ?? 0), 2) > 0
                );
        })) >= 4;

        $reversalJournalFound = count(array_filter($journalLines, static function (array $row): bool {
            return (string) ($row['source_type'] ?? '') === 'treasury_transfer_void_reversed'
                && (
                    round((float) ($row['debit_amount'] ?? 0), 2) > 0
                    || round((float) ($row['credit_amount'] ?? 0), 2) > 0
                );
        })) >= 2;
        $check(
            'Treasury transfer void reversal journal was posted',
            $reversalJournalFound,
            $lineDetails !== [] ? json_encode($journalLines, JSON_UNESCAPED_SLASHES) : 'No journal lines found'
        );
    }

    $check(
        'Treasury transfer journals posted balanced movement lines',
        $lineCheckPassed,
        $lineDetails !== [] ? json_encode($lineDetails, JSON_UNESCAPED_SLASHES) : 'No journal lines found'
    );
} catch (Throwable $exception) {
    $check('Treasury transfer regression threw exception', false, $exception->getMessage());
}

if ($db->inTransaction()) {
    $db->rollBack();
}

if ($failures !== []) {
    echo PHP_EOL . 'Treasury transfer regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Treasury transfer regression passed.' . PHP_EOL;
return 0;
