<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Services\SupplierAccountBalanceService;

const REFERENCE = 'AIRBLUE-PRIOR-20260430-75277';
const AMOUNT = 75277;
const SUPPLIER_ID = 349;
const BRANCH_ID = 1;
const PAYMENT_NO = 'SPAY-000267';
const TREASURY_ACCOUNT_ID = 33;

$mode = strtolower(trim((string) ($argv[1] ?? 'preview')));
if (! in_array($mode, ['preview', 'apply'], true)) {
    fwrite(STDERR, "Usage: php database/patches/2026_10_26_airblue_prior_period_adjustment.php [preview|apply ACTOR_USER_ID]\n");
    exit(1);
}
$actorId = (int) ($argv[2] ?? 0);
if ($mode === 'apply' && $actorId <= 0) {
    fwrite(STDERR, "Apply requires an actor user ID.\n");
    exit(1);
}

try {
    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $cashPosition = static function () use ($db): float {
        $statement = $db->query(
            'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE coa.code = "CASH_CASH_SWT_PKR_PKR"
               AND je.branch_id = 1 AND je.currency = "PKR"'
        );
        return round((float) $statement->fetchColumn(), 2);
    };
    $supplierPosition = static function () use ($app): array {
        $all = (new SupplierAccountBalanceService($app))->summaries([1, 2], null, null, 'PKR');
        return $all[SUPPLIER_ID]['PKR'] ?? throw new RuntimeException('AirBlue PKR balance is missing.');
    };

    $paymentStatement = $db->prepare(
        'SELECT id, supplier_id, branch_id, payment_date, currency, paid_amount,
                treasury_account_id, status
         FROM supplier_payments WHERE payment_no = :payment_no LIMIT 1'
    );
    $paymentStatement->execute(['payment_no' => PAYMENT_NO]);
    $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
    if (! is_array($payment)
        || (int) $payment['supplier_id'] !== SUPPLIER_ID
        || (int) $payment['branch_id'] !== BRANCH_ID
        || (string) $payment['payment_date'] !== '2026-05-01'
        || (string) $payment['currency'] !== 'PKR'
        || round((float) $payment['paid_amount'], 2) !== 1165448.0
        || (int) $payment['treasury_account_id'] !== TREASURY_ACCOUNT_ID
        || (string) $payment['status'] === 'void') {
        throw new RuntimeException('The May 1 AirBlue cash payment does not match the verified baseline.');
    }
    $oldCashJournal = $db->prepare(
        'SELECT COUNT(*) FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.source_reference = :payment_no AND je.source_type = "supplier_payment_recorded"
           AND coa.code = "CASH_CASH_SWT_PKR_PKR" AND jel.credit_amount = 1165448.00'
    );
    $oldCashJournal->execute(['payment_no' => PAYMENT_NO]);
    if ((int) $oldCashJournal->fetchColumn() !== 1) {
        throw new RuntimeException('The May 1 AirBlue cash journal is missing or duplicated.');
    }
    $snapshotColumn = $db->query('SHOW COLUMNS FROM journal_entry_lines LIKE "supplier_id_snapshot"')->fetch();
    $equityAccount = $db->query(
        'SELECT id FROM chart_of_accounts WHERE code = "PRIOR_PERIOD_SUPPLIER_ADJUSTMENT"
           AND account_type = "equity" AND is_active = 1'
    )->fetchColumn();
    if ($snapshotColumn === false || $equityAccount === false) {
        throw new RuntimeException('Run the supplier snapshot and prior-period adjustment migrations first.');
    }
    if ($mode === 'apply') {
        $lockStatement = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockStatement->execute(['lock_name' => REFERENCE]);
        if ((int) $lockStatement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not lock this historical adjustment against duplicate posting.');
        }
    }
    $existingStatement = $db->prepare(
        'SELECT id FROM journal_entries WHERE source_reference = :reference
           AND source_type = "supplier_prior_period_adjustment" LIMIT 1'
    );
    $existingStatement->execute(['reference' => REFERENCE]);
    $existingId = $existingStatement->fetchColumn();
    if ($existingId !== false) {
        $existingLines = $db->prepare(
            'SELECT coa.code, jel.supplier_id_snapshot, jel.debit_amount, jel.credit_amount
             FROM journal_entry_lines jel INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.journal_entry_id = :journal_id ORDER BY jel.id'
        );
        $existingLines->execute(['journal_id' => $existingId]);
        $existingLines = $existingLines->fetchAll(PDO::FETCH_ASSOC);
        if (count($existingLines) !== 2
            || $existingLines[0]['code'] !== 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT'
            || $existingLines[1]['code'] !== 'SUPPLIER_ADVANCES'
            || (int) $existingLines[0]['supplier_id_snapshot'] !== SUPPLIER_ID
            || (int) $existingLines[1]['supplier_id_snapshot'] !== SUPPLIER_ID
            || (float) $existingLines[0]['debit_amount'] !== (float) AMOUNT
            || (float) $existingLines[1]['credit_amount'] !== (float) AMOUNT) {
            throw new RuntimeException('Existing historical adjustment has different accounting lines.');
        }
    }
    $before = $supplierPosition();
    $cashBefore = $cashPosition();
    if ($mode === 'preview' || $existingId !== false) {
        echo json_encode([
            'mode' => $mode,
            'existing_journal_id' => $existingId === false ? null : (int) $existingId,
            'supplier_id' => SUPPLIER_ID,
            'payment_no' => PAYMENT_NO,
            'cash_paid' => 1165448,
            'historical_non_cash_charge' => AMOUNT,
            'supplier_position_before' => $before,
            'expected_supplier_advance_if_applied' => $existingId === false
                ? round((float) $before['net'] - AMOUNT, 2)
                : (float) $before['net'],
            'cash_swt_pkr_before_and_after' => $cashBefore,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $actorStatement = $db->prepare('SELECT id FROM users WHERE id = :actor_id AND is_active = 1');
    $actorStatement->execute(['actor_id' => $actorId]);
    if ($actorStatement->fetchColumn() === false) {
        throw new RuntimeException('Choose an active actor user ID.');
    }

    $db->beginTransaction();
    try {
        $journalId = (new AccountingRepository($app))->postJournalEntry([
            'branch_id' => BRANCH_ID,
            'booking_reference' => null,
            'source_type' => 'supplier_prior_period_adjustment',
            'source_reference' => REFERENCE,
            'entry_date' => '2026-04-30',
            'currency' => 'PKR',
            'narration' => 'Client-confirmed AirBlue charge from transactions before the May 2026 opening payment; no additional cash movement',
            'actor_user_id' => $actorId,
        ], [
            [
                'account_code' => 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT',
                'supplier_id_snapshot' => SUPPLIER_ID,
                'debit_amount' => AMOUNT,
                'credit_amount' => 0,
                'line_description' => 'Historical AirBlue supplier charge outside current service profit',
            ],
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'supplier_id_snapshot' => SUPPLIER_ID,
                'debit_amount' => 0,
                'credit_amount' => AMOUNT,
                'line_description' => 'Reduce AirBlue opening supplier credit without changing the cash payment',
            ],
        ]);
        $after = $supplierPosition();
        $cashAfter = $cashPosition();
        if (round((float) $after['net'] - (float) $before['net'], 2) !== -(float) AMOUNT
            || round((float) $after['bills'] - (float) $before['bills'], 2) !== (float) AMOUNT
            || $cashAfter !== $cashBefore) {
            throw new RuntimeException('Supplier or cash balance did not change by exactly the intended amount: '
                . json_encode(['before' => $before, 'after' => $after,
                    'cash_before' => $cashBefore, 'cash_after' => $cashAfter]));
        }
        $paymentStatement->execute(['payment_no' => PAYMENT_NO]);
        $afterPayment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
        if (round((float) ($afterPayment['paid_amount'] ?? 0), 2) !== 1165448.0) {
            throw new RuntimeException('The May 1 payment amount changed unexpectedly.');
        }
        $db->commit();
        echo json_encode([
            'journal_id' => $journalId,
            'reference' => REFERENCE,
            'supplier_position_before' => $before,
            'supplier_position_after' => $after,
            'cash_swt_pkr_before' => $cashBefore,
            'cash_swt_pkr_after' => $cashAfter,
            'payment_unchanged' => 1165448,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'AirBlue adjustment failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
