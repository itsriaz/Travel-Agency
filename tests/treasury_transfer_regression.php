<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
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
    exit(1);
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

    $insufficientRejected = false;
    $insufficientMessage = '';
    try {
        $treasuryRepository->saveTransfer([
            'branch_id' => $pair['branch_id'],
            'transaction_type' => 'cash_deposit_to_bank',
            'transaction_date' => $today,
            'currency' => $pair['currency'],
            'from_treasury_account_id' => (int) ($pair['cash']['id'] ?? 0),
            'to_treasury_account_id' => (int) ($pair['bank']['id'] ?? 0),
            'amount' => '99999999.00',
            'reference_no' => 'RG-INSUFFICIENT-001',
            'narration' => 'Automated insufficient balance regression',
            'created_by_user_id' => $actorUserId,
        ], [$pair['branch_id']]);
    } catch (Throwable $exception) {
        $insufficientRejected = str_contains($exception->getMessage(), 'does not have enough balance');
        $insufficientMessage = $exception->getMessage();
    }
    $check(
        'Treasury transfer rejects source amounts above available balance',
        $insufficientRejected,
        $insufficientMessage
    );

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
    exit(1);
}

echo PHP_EOL . 'Treasury transfer regression passed.' . PHP_EOL;
