<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\TreasuryRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = "Usage: php scripts/repair_alsaudia_cross_branch_treasury_funding_20260924.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-f0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}

$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$rehearse = isset($options['rehearse']);
$apply = isset($options['apply']);
if ($rehearse && $apply) {
    fwrite(STDERR, $usage);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$expectedIds = range(175, 196);
$expectedTotal = 19457566.00;
$repairEvent = 'supplier.cross_branch_treasury_history.repaired';

try {
    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $treasury = new TreasuryRepository($app);

    $loadCandidates = static function () use ($db, $expectedIds): array {
        $placeholders = implode(',', array_fill(0, count($expectedIds), '?'));
        $statement = $db->prepare(
            'SELECT
                sp.id,
                sp.payment_no,
                sp.payment_date,
                sp.supplier_id,
                sp.branch_id AS payment_branch_id,
                sp.booking_reference,
                sp.payment_scope,
                sp.currency,
                sp.paid_amount,
                sp.allocated_amount,
                sp.unallocated_amount,
                sp.returned_amount,
                sp.converted_advance_amount,
                sp.converted_advance_id,
                sp.charges_amount,
                sp.status,
                sp.treasury_account_id,
                s.code AS supplier_code,
                s.name AS supplier_name,
                ta.branch_id AS source_branch_id,
                ta.currency AS source_currency,
                ta.account_name AS source_account_name,
                ta.account_code AS source_account_code,
                ta.linked_account_id AS source_ledger_account_id
             FROM supplier_payments sp
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             INNER JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
             WHERE sp.id IN (' . $placeholders . ')
               AND sp.status <> "void"
               AND ta.branch_id <> sp.branch_id
               AND ta.currency = sp.currency
               AND NOT EXISTS (
                    SELECT 1
                    FROM journal_entries funding_je
                    INNER JOIN journal_entry_lines funding_jel
                            ON funding_jel.journal_entry_id = funding_je.id
                    WHERE funding_je.source_type = "supplier_payment_treasury_funded"
                      AND funding_je.branch_id = ta.branch_id
                      AND funding_je.currency = ta.currency
                      AND funding_jel.supplier_payment_id = sp.id
               )
             ORDER BY sp.id'
        );
        $statement->execute($expectedIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $journalEvidence = static function (int $paymentId, int $sourceLedgerAccountId) use ($db): array {
        $statement = $db->prepare(
            'SELECT
                je.id,
                je.branch_id,
                je.currency,
                je.source_reference,
                ROUND(SUM(jel.debit_amount), 2) AS total_debits,
                ROUND(SUM(jel.credit_amount), 2) AS total_credits,
                ROUND(SUM(CASE WHEN jel.account_id = :source_account_debit THEN jel.debit_amount ELSE 0 END), 2) AS source_debit,
                ROUND(SUM(CASE WHEN jel.account_id = :source_account_credit THEN jel.credit_amount ELSE 0 END), 2) AS source_credit
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE je.source_type = "supplier_payment_recorded"
               AND jel.supplier_payment_id = :payment_id
             GROUP BY je.id, je.branch_id, je.currency, je.source_reference
             ORDER BY je.id'
        );
        $statement->execute([
            'source_account_debit' => $sourceLedgerAccountId,
            'source_account_credit' => $sourceLedgerAccountId,
            'payment_id' => $paymentId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $buildPlan = static function (array $candidates) use ($db, $journalEvidence, $expectedIds, $expectedTotal, $treasury): array {
        if ($candidates === []) {
            return [];
        }
        if (array_map('intval', array_column($candidates, 'id')) !== $expectedIds) {
            throw new RuntimeException('The reviewed AL SAUDIA payment ID scope changed.');
        }

        $rows = [];
        $total = 0.0;
        foreach ($candidates as $row) {
            $id = (int) ($row['id'] ?? 0);
            $amount = round((float) ($row['paid_amount'] ?? 0) + (float) ($row['charges_amount'] ?? 0), 2);
            $evidence = $journalEvidence($id, (int) ($row['source_ledger_account_id'] ?? 0));
            $expectedPaymentNo = sprintf('SPAY-%06d', $id - 12);
            $allocationVerified = abs((float) ($row['paid_amount'] ?? 0) - (float) ($row['allocated_amount'] ?? 0)) <= 0.005;
            if ($id === 180) {
                $advanceStatement = $db->prepare('SELECT id, supplier_id, branch_id, currency, deposit_amount
                    FROM supplier_advances WHERE source_supplier_payment_id = ? ORDER BY id');
                $advanceStatement->execute([$id]);
                $advances = $advanceStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $allocationVerified = abs((float) ($row['paid_amount'] ?? 0) - 500000.0) <= 0.005
                    && abs((float) ($row['allocated_amount'] ?? 0) - 449758.0) <= 0.005
                    && abs((float) ($row['converted_advance_amount'] ?? 0) - 50242.0) <= 0.005
                    && abs((float) ($row['unallocated_amount'] ?? 0)) <= 0.005
                    && abs((float) ($row['returned_amount'] ?? 0)) <= 0.005
                    && count($advances) === 1
                    && (int) ($advances[0]['id'] ?? 0) === (int) ($row['converted_advance_id'] ?? 0)
                    && (int) ($advances[0]['supplier_id'] ?? 0) === 413
                    && (int) ($advances[0]['branch_id'] ?? 0) === 2
                    && (string) ($advances[0]['currency'] ?? '') === 'PKR'
                    && abs((float) ($advances[0]['deposit_amount'] ?? 0) - 50242.0) <= 0.005;
            }
            if ((int) ($row['supplier_id'] ?? 0) !== 413
                || (string) ($row['supplier_code'] ?? '') !== 'SUP-00012'
                || (string) ($row['supplier_name'] ?? '') !== 'AL SAUDIA AED'
                || (int) ($row['payment_branch_id'] ?? 0) !== 2
                || (int) ($row['source_branch_id'] ?? 0) !== 1
                || (int) ($row['treasury_account_id'] ?? 0) !== 33
                || (int) ($row['source_ledger_account_id'] ?? 0) !== 18
                || (string) ($row['source_account_code'] ?? '') !== 'CASH_CASH_SWT_PKR_PKR'
                || (string) ($row['currency'] ?? '') !== 'PKR'
                || (string) ($row['source_currency'] ?? '') !== 'PKR'
                || (string) ($row['payment_scope'] ?? '') !== 'global'
                || (string) ($row['booking_reference'] ?? '') !== 'GLOBAL'
                || (string) ($row['payment_no'] ?? '') !== $expectedPaymentNo
                || abs((float) ($row['charges_amount'] ?? 0)) > 0.005
                || ! $allocationVerified
                || count($evidence) !== 1) {
                throw new RuntimeException('Payment ' . $id . ' differs from the reviewed AL SAUDIA AED repair scope.');
            }

            $journal = $evidence[0];
            if ((int) ($journal['branch_id'] ?? 0) !== 2
                || (string) ($journal['currency'] ?? '') !== 'PKR'
                || (string) ($journal['source_reference'] ?? '') !== $expectedPaymentNo
                || abs((float) ($journal['total_debits'] ?? 0) - $amount) > 0.005
                || abs((float) ($journal['total_credits'] ?? 0) - $amount) > 0.005
                || abs((float) ($journal['source_debit'] ?? 0)) > 0.005
                || abs((float) ($journal['source_credit'] ?? 0) - $amount) > 0.005) {
                throw new RuntimeException('Original journal evidence changed for payment ' . $id . '.');
            }

            $rows[] = [
                'payment_id' => $id,
                'payment_no' => $expectedPaymentNo,
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'amount_PKR' => $amount,
                'original_journal_id' => (int) ($journal['id'] ?? 0),
            ];
            $total += $amount;
        }

        $total = round($total, 2);
        if (abs($total - $expectedTotal) > 0.005) {
            throw new RuntimeException('The reviewed AL SAUDIA payment total changed.');
        }

        $balanceBefore = $treasury->currentBalanceForAccountId(33);

        return [
            'repair' => 'AL SAUDIA AED cross-branch Swat PKR treasury funding',
            'payment_count' => count($rows),
            'payment_ids' => array_column($rows, 'payment_id'),
            'total_PKR' => $total,
            'source_treasury_account_id' => 33,
            'source_account' => 'Cash-SWT-PKR',
            'source_balance_before_PKR' => $balanceBefore,
            'source_balance_after_PKR' => round($balanceBefore - $total, 2),
            'payments' => $rows,
        ];
    };

    $fingerprint = static function (array $plan): string {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    };
    $hashTable = static function (string $table) use ($db): string {
        return hash('sha256', json_encode(
            $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        ));
    };

    $candidates = $loadCandidates();
    if ($candidates === []) {
        $auditStatement = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE event_name = :event_name');
        $auditStatement->execute(['event_name' => $repairEvent]);
        if ((int) $auditStatement->fetchColumn() > 0) {
            echo json_encode(['mode' => 'already-applied', 'repair' => $repairEvent], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
            exit(0);
        }
        throw new RuntimeException('No repair candidates were found, but no completed repair audit exists. Stop and investigate.');
    }

    $plan = $buildPlan($candidates);
    $planFingerprint = $fingerprint($plan);
    if (! $rehearse && ! $apply) {
        echo json_encode(array_merge(['mode' => 'preview', 'fingerprint' => $planFingerprint], $plan), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actorId = (int) ($options['actor'] ?? 0);
    $suppliedFingerprint = strtolower(trim((string) ($options['fingerprint'] ?? '')));
    if ($actorId <= 0 || $suppliedFingerprint === '' || ! hash_equals($planFingerprint, $suppliedFingerprint)) {
        throw new RuntimeException('Specify the reviewed --actor and exact --fingerprint from preview.');
    }
    $actorStatement = $db->prepare(
        'SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id AND u.is_active = 1 AND r.code = "super_admin"'
    );
    $actorStatement->execute(['id' => $actorId]);
    if (! $actorStatement->fetchColumn()) {
        throw new RuntimeException('The repair actor must be an active super administrator.');
    }

    $protectedTables = [
        'supplier_payments',
        'supplier_payment_allocations',
        'supplier_obligations',
        'supplier_advances',
        'treasury_accounts',
        'treasury_transactions',
        'bookings',
        'booking_services',
    ];
    $protectedHashes = [];
    foreach ($protectedTables as $table) {
        $protectedHashes[$table] = $hashTable($table);
    }

    $db->beginTransaction();
    $lockedRows = $db->query(
        'SELECT id FROM supplier_payments WHERE id BETWEEN 175 AND 196 ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (array_map('intval', $lockedRows) !== $expectedIds) {
        throw new RuntimeException('The reviewed payment rows changed while acquiring locks.');
    }
    $lockedPlan = $buildPlan($loadCandidates());
    if (! hash_equals($planFingerprint, $fingerprint($lockedPlan))) {
        throw new RuntimeException('The repair scope changed while acquiring locks.');
    }

    $bridgeStatement = $db->prepare(
        'SELECT id, account_type, normal_balance
         FROM chart_of_accounts
         WHERE code = "SUPPLIER_PAYMENT_FX_CLEARING"
         LIMIT 1
         FOR UPDATE'
    );
    $bridgeStatement->execute();
    $bridge = $bridgeStatement->fetch(PDO::FETCH_ASSOC);
    if ($bridge === false) {
        $insertBridge = $db->prepare(
            'INSERT INTO chart_of_accounts (
                code, name, purpose, account_type, normal_balance, is_system, is_active
             ) VALUES (
                "SUPPLIER_PAYMENT_FX_CLEARING", "Supplier Payment FX Clearing",
                "Cross-branch and cross-currency supplier payment funding", "asset", "debit", 1, 1
             )'
        );
        $insertBridge->execute();
        $bridgeAccountId = (int) $db->lastInsertId();
    } else {
        if ((string) ($bridge['account_type'] ?? '') !== 'asset' || (string) ($bridge['normal_balance'] ?? '') !== 'debit') {
            throw new RuntimeException('The supplier payment clearing account has an unexpected accounting type.');
        }
        $bridgeAccountId = (int) ($bridge['id'] ?? 0);
    }
    if ($bridgeAccountId <= 0) {
        throw new RuntimeException('The supplier payment clearing account could not be resolved.');
    }

    $accounting = new AccountingRepository($app);
    $newJournalIds = [];
    foreach ($candidates as $row) {
        $paymentId = (int) $row['id'];
        $paymentNo = (string) $row['payment_no'];
        $amount = round((float) $row['paid_amount'] + (float) $row['charges_amount'], 2);
        $common = [
            'booking_reference' => null,
            'source_type' => 'supplier_payment_treasury_funded',
            'entry_date' => (string) $row['payment_date'],
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
        ];

        $newJournalIds[] = $accounting->postJournalEntry(array_merge($common, [
            'branch_id' => (int) $row['payment_branch_id'],
            'source_reference' => $paymentNo . '-BRANCH-REPAIR',
            'narration' => 'Historical correction: move supplier payment funding to the selected source branch',
        ]), [
            [
                'account_id' => (int) $row['source_ledger_account_id'],
                'supplier_payment_id' => $paymentId,
                'line_description' => 'Reverse cash credit posted in the supplier payment branch',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_id' => $bridgeAccountId,
                'supplier_payment_id' => $paymentId,
                'line_description' => 'Supplier payment funding bridge credited',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);

        $newJournalIds[] = $accounting->postJournalEntry(array_merge($common, [
            'branch_id' => (int) $row['source_branch_id'],
            'source_reference' => $paymentNo . '-FUNDING-REPAIR',
            'narration' => 'Historical correction: supplier payment funded from selected Swat PKR account',
        ]), [
            [
                'account_id' => $bridgeAccountId,
                'supplier_payment_id' => $paymentId,
                'line_description' => 'Supplier payment funding bridge debited',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_id' => (int) $row['source_ledger_account_id'],
                'supplier_payment_id' => $paymentId,
                'line_description' => 'Cash or bank supplier outflow',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);
    }

    if (count($newJournalIds) !== count($expectedIds) * 2) {
        throw new RuntimeException('The expected repair journal count was not created.');
    }
    $journalPlaceholders = implode(',', array_fill(0, count($newJournalIds), '?'));
    $journalCheck = $db->prepare(
        'SELECT je.id,
                ROUND(SUM(jel.debit_amount), 2) AS debits,
                ROUND(SUM(jel.credit_amount), 2) AS credits
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.id IN (' . $journalPlaceholders . ')
         GROUP BY je.id
         HAVING ABS(debits - credits) > 0.005'
    );
    $journalCheck->execute($newJournalIds);
    if (($journalCheck->fetchAll(PDO::FETCH_ASSOC) ?: []) !== []) {
        throw new RuntimeException('One or more historical correction journals are not balanced.');
    }

    if ($loadCandidates() !== []) {
        throw new RuntimeException('A reviewed payment still lacks source-branch funding evidence after repair.');
    }
    foreach ($protectedTables as $table) {
        if (! hash_equals($protectedHashes[$table], $hashTable($table))) {
            throw new RuntimeException('Protected data changed unexpectedly: ' . $table . '.');
        }
    }

    $balanceAfter = $treasury->currentBalanceForAccountId(33);
    $expectedBalanceAfter = (float) $plan['source_balance_after_PKR'];
    if (abs($balanceAfter - $expectedBalanceAfter) > 0.005) {
        throw new RuntimeException(
            'Swat PKR source balance did not reduce by the reviewed amount. Expected '
            . number_format($expectedBalanceAfter, 2)
            . ', found '
            . number_format($balanceAfter, 2)
            . '.'
        );
    }

    $result = [
        'mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back',
        'payment_count' => count($expectedIds),
        'payment_ids' => $expectedIds,
        'total_PKR' => $expectedTotal,
        'source_treasury_account_id' => 33,
        'source_balance_before_PKR' => (float) $plan['source_balance_before_PKR'],
        'source_balance_after_PKR' => $balanceAfter,
        'new_journal_ids' => $newJournalIds,
        'protected_tables_verified' => $protectedTables,
        'fingerprint' => $planFingerprint,
    ];
    $auditStatement = $db->prepare(
        'INSERT INTO audit_logs (event_name, actor_user_id, payload_json)
         VALUES (:event_name, :actor_user_id, :payload_json)'
    );
    $auditStatement->execute([
        'event_name' => $repairEvent,
        'actor_user_id' => $actorId,
        'payload_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ]);
    if ($auditStatement->rowCount() !== 1) {
        throw new RuntimeException('The repair audit record could not be written.');
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
        $balanceAfterRollback = $treasury->currentBalanceForAccountId(33);
        if (abs($balanceAfterRollback - (float) $plan['source_balance_before_PKR']) > 0.005) {
            throw new RuntimeException('Rollback did not restore the original Swat PKR source balance.');
        }
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
