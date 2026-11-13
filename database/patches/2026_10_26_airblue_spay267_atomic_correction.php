<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Services\SupplierAccountBalanceService;

// One verified historical correction. Preview and dry-run never commit money.
$mode = strtolower((string) ($argv[1] ?? 'preview'));
$actorId = (int) ($argv[2] ?? 0);
if (! in_array($mode, ['preview', 'dry-run', 'apply'], true)
    || ($mode !== 'preview' && $actorId <= 0)) {
    fwrite(STDERR, "Usage: php database/patches/2026_10_26_airblue_spay267_atomic_correction.php [preview|dry-run ACTOR_ID|apply ACTOR_ID]\n");
    exit(1);
}

const CORRECTION = 'AIRBLUE-SPAY267-75277-ATOMIC';
const HISTORICAL = 'AIRBLUE-PRIOR-20260430-75277';
const SUPPLIER = 349;
const DIFFERENCE = 75277;
const REPLACEMENTS = [
    // old application ID => [obligation ID, old amount, replacement amount, booking]
    162 => [791, 1000, 728, 'BK-000787'],
    163 => [865, 1000, 1000, 'BK-000862'],
    164 => [1070, 43782, 43782, 'BK-001076'],
    165 => [1076, 20000, 20000, 'BK-001082'],
    166 => [1109, 1787, 1787, 'BK-001117'],
    173 => [1137, 7980, 7980, 'BK-001146'],
];

function one(PDO $db, string $sql, array $params = []): array
{
    $query = $db->prepare($sql);
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        throw new RuntimeException('Expected source row is missing: ' . substr($sql, 0, 90));
    }
    return $row;
}

function money(mixed $value): int
{
    $rounded = round((float) $value, 2);
    if (abs($rounded - round($rounded)) > 0.005) {
        throw new RuntimeException('This approved correction only supports whole PKR amounts.');
    }
    return (int) round($rounded);
}

function same(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function position(App $app): array
{
    $summary = (new SupplierAccountBalanceService($app))->summaries([1, 2], null, null, 'PKR');
    return $summary[SUPPLIER]['PKR'] ?? throw new RuntimeException('AirBlue account summary is missing.');
}

function cashPosition(PDO $db): int
{
    $row = one($db, 'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS net
        FROM journal_entry_lines jel JOIN journal_entries je ON je.id = jel.journal_entry_id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE coa.code = "CASH_CASH_SWT_PKR_PKR" AND je.branch_id = 1 AND je.currency = "PKR"');
    return (int) round((float) $row['net'] * 100);
}

function journal(AccountingRepository $accounting, string $type, string $suffix, string $date,
    int $branch, ?string $booking, int $actor, string $narration, array $lines): int
{
    return $accounting->postJournalEntry([
        'branch_id' => $branch, 'booking_reference' => $booking,
        'source_type' => $type, 'source_reference' => CORRECTION . '-' . $suffix,
        'entry_date' => $date, 'currency' => 'PKR', 'narration' => $narration,
        'actor_user_id' => $actor,
    ], $lines);
}

try {
    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $accounting = new AccountingRepository($app);
    $lockHeld = false;
    if ($mode !== 'preview') {
        $lock = one($db, 'SELECT GET_LOCK(:name, 10) AS acquired', ['name' => CORRECTION]);
        same((int) $lock['acquired'] === 1, 'Another AirBlue correction is running.');
        $lockHeld = true;
    }
    try {
        if ($mode !== 'preview') {
            $db->beginTransaction();
        }
        $previous = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE source_reference LIKE :prefix');
        $previous->execute(['prefix' => CORRECTION . '-%']);
        same((int) $previous->fetchColumn() === 0, 'This correction already has journal entries; refusing duplicate posting.');

        $payment = one($db, 'SELECT * FROM supplier_payments WHERE id = 279' . ($mode === 'preview' ? '' : ' FOR UPDATE'));
        same($payment['payment_no'] === 'SPAY-000267' && (int) $payment['supplier_id'] === SUPPLIER
            && (int) $payment['branch_id'] === 1 && $payment['currency'] === 'PKR'
            && $payment['payment_date'] === '2026-05-01' && $payment['status'] === 'fully_allocated'
            && (int) $payment['treasury_account_id'] === 33
            && money($payment['paid_amount']) === 1165448
            && money($payment['allocated_amount']) === 1089899
            && money($payment['converted_advance_amount']) === 75549
            && (int) $payment['converted_advance_id'] === 69
            && money($payment['unallocated_amount']) === 0
            && money($payment['returned_amount']) === 0
            && money($payment['charges_amount']) === 0,
            'SPAY-000267 differs from the reviewed source payment.');
        $allocated = one($db, 'SELECT COUNT(*) AS n, COALESCE(SUM(allocated_amount),0) AS total
            FROM supplier_payment_allocations WHERE supplier_payment_id = 279');
        same((int) $allocated['n'] === 11 && money($allocated['total']) === 1089899,
            'The eleven direct payment allocations changed.');
        $sourceJournal = one($db, 'SELECT id, branch_id, currency FROM journal_entries
            WHERE source_type = "supplier_payment_recorded" AND source_reference = "SPAY-000267"');
        same((int) $sourceJournal['id'] === 7735 && (int) $sourceJournal['branch_id'] === 1
            && $sourceJournal['currency'] === 'PKR', 'The original cash journal changed.');
        $cashLine = one($db, 'SELECT jel.credit_amount FROM journal_entry_lines jel
            JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE jel.journal_entry_id = 7735 AND coa.code = "CASH_CASH_SWT_PKR_PKR"');
        same(money($cashLine['credit_amount']) === 1165448, 'Original cash outflow is different.');

        $advances = [];
        foreach ([36 => [202, 400000], 69 => [279, 75549]] as $id => [$sourcePayment, $deposit]) {
            $row = one($db, 'SELECT * FROM supplier_advances WHERE id = :id'
                . ($mode === 'preview' ? '' : ' FOR UPDATE'), ['id' => $id]);
            same((int) $row['supplier_id'] === SUPPLIER && (int) $row['branch_id'] === 1
                && $row['currency'] === 'PKR' && (int) $row['source_supplier_payment_id'] === $sourcePayment
                && money($row['deposit_amount']) === $deposit && money($row['available_amount']) === 0,
                'Supplier advance #' . $id . ' changed.');
            $used = one($db, 'SELECT COALESCE(SUM(applied_amount),0) AS total FROM supplier_advance_applications
                WHERE supplier_advance_id = :id', ['id' => $id]);
            same(money($used['total']) === $deposit, 'Advance #' . $id . ' applications no longer conserve its deposit.');
            $advances[$id] = $row;
        }
        $duplicate = one($db, 'SELECT * FROM supplier_advance_applications WHERE id = 94'
            . ($mode === 'preview' ? '' : ' FOR UPDATE'));
        same((int) $duplicate['supplier_advance_id'] === 36
            && (int) $duplicate['supplier_obligation_id'] === 293
            && money($duplicate['applied_amount']) === 160837
            && money($duplicate['advance_amount_consumed']) === 160837
            && money($duplicate['obligation_amount_applied']) === 160837,
            'The duplicate BK-000297 advance application changed.');
        $duplicateJournal = one($db, 'SELECT COUNT(*) AS n FROM journal_entries je
            JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.source_type = "supplier_advance_applied" AND je.branch_id = 1
              AND je.currency = "PKR" AND jel.supplier_obligation_id = 293
              AND coa.code = "AP_CONTROL" AND jel.debit_amount = 160837.00');
        same((int) $duplicateJournal['n'] === 1,
            'The duplicate advance application does not have one matching AP journal line.');
        $covered = one($db, 'SELECT COUNT(*) AS n, COALESCE(SUM(spa.allocated_amount),0) AS paid
            FROM supplier_payment_allocations spa JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
            WHERE spa.supplier_obligation_id = 293 AND sp.status <> "void"');
        same((int) $covered['n'] === 1 && money($covered['paid']) === 160837,
            'BK-000297 is not independently covered by the saved direct payment.');
        $obligation297 = one($db, 'SELECT * FROM supplier_obligations WHERE id = 293'
            . ($mode === 'preview' ? '' : ' FOR UPDATE'));
        same((int) $obligation297['supplier_id'] === SUPPLIER && (int) $obligation297['branch_id'] === 1
            && $obligation297['currency'] === 'PKR' && money($obligation297['gross_amount']) === 160837
            && money($obligation297['advance_applied_amount']) === 160837
            && money($obligation297['net_payable_amount']) === 0,
            'BK-000297 obligation changed.');

        $replacementRows = [];
        foreach (REPLACEMENTS as $id => [$obligationId, $oldAmount, $replacementAmount, $booking]) {
            $application = one($db, 'SELECT * FROM supplier_advance_applications WHERE id = :id'
                . ($mode === 'preview' ? '' : ' FOR UPDATE'), ['id' => $id]);
            $obligation = one($db, 'SELECT * FROM supplier_obligations WHERE id = :id'
                . ($mode === 'preview' ? '' : ' FOR UPDATE'), ['id' => $obligationId]);
            same((int) $application['supplier_advance_id'] === 69
                && (int) $application['supplier_obligation_id'] === $obligationId
                && money($application['applied_amount']) === $oldAmount
                && money($application['advance_amount_consumed']) === $oldAmount
                && money($application['obligation_amount_applied']) === $oldAmount
                && $obligation['booking_reference'] === $booking
                && (int) $obligation['supplier_id'] === SUPPLIER
                && (int) $obligation['branch_id'] === 2
                && $obligation['currency'] === 'PKR'
                && money($obligation['net_payable_amount']) === 0,
                'Target application or payable changed: ' . $booking);
            $replacementRows[$id] = [$application, $obligation];
        }
        $already36 = one($db, 'SELECT * FROM supplier_advance_applications WHERE id = 91'
            . ($mode === 'preview' ? '' : ' FOR UPDATE'));
        same((int) $already36['supplier_advance_id'] === 36
            && (int) $already36['supplier_obligation_id'] === 865
            && money($already36['applied_amount']) === 70004,
            'The existing BK-000862 advance application changed.');
        $aggregated = one($db, 'SELECT COUNT(*) AS n FROM journal_entries je
            JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.source_type = "supplier_advance_applied" AND je.branch_id = 2
              AND je.currency = "PKR" AND jel.supplier_obligation_id = 1137
              AND coa.code = "AP_CONTROL" AND jel.debit_amount = 356761.00');
        same((int) $aggregated['n'] === 1,
            'The aggregated BK-001146 journal changed; refusing a partial adjustment.');

        $prior = one($db, 'SELECT id FROM journal_entries WHERE source_type = "supplier_prior_period_adjustment"
            AND source_reference = :reference', ['reference' => HISTORICAL]);
        $priorId = (int) $prior['id'];
        $priorLines = $db->prepare('SELECT coa.code, jel.supplier_id_snapshot, jel.debit_amount, jel.credit_amount
            FROM journal_entry_lines jel JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE jel.journal_entry_id = :id ORDER BY jel.id');
        $priorLines->execute(['id' => $priorId]);
        $lines = $priorLines->fetchAll(PDO::FETCH_ASSOC);
        same(count($lines) === 2 && $lines[0]['code'] === 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT'
            && $lines[1]['code'] === 'SUPPLIER_ADVANCES'
            && (int) $lines[0]['supplier_id_snapshot'] === SUPPLIER
            && (int) $lines[1]['supplier_id_snapshot'] === SUPPLIER
            && money($lines[0]['debit_amount']) === DIFFERENCE
            && money($lines[1]['credit_amount']) === DIFFERENCE,
            'The earlier historical journal differs from the one being reversed.');
        $actor = $mode === 'preview' ? null : one($db,
            'SELECT id FROM users WHERE id = :id AND is_active = 1', ['id' => $actorId]);
        $before = ['supplier' => position($app), 'cash_cents' => cashPosition($db)];
        $plan = [
            'mode' => $mode, 'payment_no' => 'SPAY-000267',
            'paid_before' => 1165448, 'paid_after' => 1090171,
            'direct_allocations_unchanged' => 1089899,
            'advance_69_after' => ['deposit' => 272, 'used' => 272, 'available' => 0],
            'advance_36_after' => ['deposit' => 400000, 'used' => 314440, 'available' => 85560],
            'duplicate_application_removed' => 94,
            'prior_adjustment_reversed' => $priorId,
            'supplier_before' => $before['supplier'],
            'cash_net_before' => $before['cash_cents'] / 100,
            'cash_net_expected_after' => ($before['cash_cents'] + DIFFERENCE * 100) / 100,
        ];
        if ($mode === 'preview') {
            echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }

        $journalIds = [];
        $db->prepare('UPDATE supplier_payments SET paid_amount = 1090171, converted_advance_amount = 272
            WHERE id = 279')->execute();
        $db->prepare('UPDATE supplier_advances SET deposit_amount = 272 WHERE id = 69')->execute();
        $db->prepare('UPDATE supplier_advances SET available_amount = 85560 WHERE id = 36')->execute();
        $db->prepare('DELETE FROM supplier_advance_applications WHERE id = 94')->execute();
        $db->prepare('UPDATE supplier_obligations SET advance_applied_amount = 0, status = "paid"
            WHERE id = 293')->execute();

        $updateOld = $db->prepare('UPDATE supplier_advance_applications SET applied_amount = :amount,
            advance_amount_consumed = :amount2, obligation_amount_applied = :amount3 WHERE id = :id');
        $deleteOld = $db->prepare('DELETE FROM supplier_advance_applications WHERE id = :id');
        $insertNew = $db->prepare('INSERT INTO supplier_advance_applications
            (supplier_advance_id, supplier_obligation_id, applied_amount, application_type,
             advance_currency, advance_amount_consumed, obligation_currency, obligation_amount_applied,
             rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
             application_note, created_by_user_id, created_at)
             VALUES (36, :obligation_id, :amount, :type, "PKR", :consumed, "PKR", :applied,
             "PKR", "PKR", 1.00000000, :rate_date, :note, :actor, :created_at)');
        foreach (REPLACEMENTS as $id => [$obligationId, $oldAmount, $replacementAmount, $booking]) {
            [$application] = $replacementRows[$id];
            $keep = $oldAmount - $replacementAmount;
            if ($keep > 0) {
                $updateOld->execute(['amount' => $keep, 'amount2' => $keep, 'amount3' => $keep, 'id' => $id]);
            } else {
                $deleteOld->execute(['id' => $id]);
            }
            if ($obligationId === 865) {
                $db->prepare('UPDATE supplier_advance_applications SET applied_amount = applied_amount + 1000,
                    advance_amount_consumed = advance_amount_consumed + 1000,
                    obligation_amount_applied = obligation_amount_applied + 1000 WHERE id = 91')->execute();
            } else {
                $insertNew->execute([
                    'obligation_id' => $obligationId, 'amount' => $replacementAmount,
                    'type' => $application['application_type'], 'consumed' => $replacementAmount,
                    'applied' => $replacementAmount,
                    'rate_date' => substr((string) $application['created_at'], 0, 10),
                    'note' => 'Replacement funding after SPAY-000267 amount correction',
                    'actor' => $actorId, 'created_at' => $application['created_at'],
                ]);
            }
        }

        $journalIds['cash_correction'] = journal($accounting, 'supplier_payment_amount_corrected',
            'CASH', '2026-05-01', 1, null, $actorId,
            'Reduce AirBlue May 1 cash payment by PKR 75,277; retain direct ticket allocations', [
                ['account_code' => 'CASH_CASH_SWT_PKR_PKR', 'supplier_payment_id' => 279,
                    'debit_amount' => DIFFERENCE, 'credit_amount' => 0],
                ['account_code' => 'SUPPLIER_ADVANCES', 'supplier_payment_id' => 279,
                    'debit_amount' => 0, 'credit_amount' => DIFFERENCE],
            ]);
        $journalIds['historical_reversal'] = journal($accounting, 'supplier_prior_period_adjustment_reversed',
            'PRIOR-REV-' . $priorId, '2026-04-30', 1, null, $actorId,
            'Reverse the previous AirBlue historical adjustment after correcting the cash payment', [
                ['account_code' => 'SUPPLIER_ADVANCES', 'supplier_id_snapshot' => SUPPLIER,
                    'debit_amount' => DIFFERENCE, 'credit_amount' => 0],
                ['account_code' => 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT', 'supplier_id_snapshot' => SUPPLIER,
                    'debit_amount' => 0, 'credit_amount' => DIFFERENCE],
            ]);
        $journalIds['duplicate_release'] = $accounting->postSupplierAdvanceApplicationAdjusted([
            'branch_id' => 1, 'booking_reference' => 'BK-000297',
            'supplier_obligation_id' => 293, 'adjustment_amount' => -160837,
            'source_reference' => CORRECTION . '-DUPLICATE-94', 'entry_date' => '2026-09-08',
            'currency' => 'PKR', 'actor_user_id' => $actorId,
            'narration' => 'Release duplicate advance application #94; direct payment #373 covers booking',
        ]);
        foreach (REPLACEMENTS as $id => [$obligationId, , $replacementAmount, $booking]) {
            $date = substr((string) $replacementRows[$id][0]['created_at'], 0, 10);
            $common = [
                'branch_id' => 2, 'booking_reference' => $booking,
                'supplier_obligation_id' => $obligationId, 'entry_date' => $date,
                'currency' => 'PKR', 'actor_user_id' => $actorId,
            ];
            $journalIds['release_' . $id] = $accounting->postSupplierAdvanceApplicationAdjusted($common + [
                'adjustment_amount' => -$replacementAmount,
                'source_reference' => CORRECTION . '-SOURCE-69-' . $id,
                'narration' => 'Release only this application amount from SPAY-000267 advance #69',
            ]);
            $journalIds['replace_' . $id] = $accounting->postSupplierAdvanceApplicationAdjusted($common + [
                'adjustment_amount' => $replacementAmount,
                'source_reference' => CORRECTION . '-SOURCE-36-' . $id,
                'narration' => 'Cover same payable from SPAY-000190 advance #36',
            ]);
        }

        $paymentAfter = one($db, 'SELECT paid_amount, allocated_amount, converted_advance_amount FROM supplier_payments WHERE id = 279');
        same(money($paymentAfter['paid_amount']) === 1090171
            && money($paymentAfter['allocated_amount']) + money($paymentAfter['converted_advance_amount']) === 1090171,
            'Corrected payment does not conserve its direct and converted uses.');
        foreach ([36 => [400000, 85560], 69 => [272, 0]] as $id => [$deposit, $available]) {
            $row = one($db, 'SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = :id', ['id' => $id]);
            $applications = one($db, 'SELECT COALESCE(SUM(applied_amount),0) AS used FROM supplier_advance_applications
                WHERE supplier_advance_id = :id', ['id' => $id]);
            same(money($row['deposit_amount']) === $deposit
                && money($row['available_amount']) === $available
                && money($applications['used']) + $available === $deposit,
                'Corrected advance #' . $id . ' does not conserve its deposit.');
        }
        foreach (array_merge([293], array_column(REPLACEMENTS, 0)) as $obligationId) {
            $obligation = one($db, 'SELECT net_payable_amount FROM supplier_obligations WHERE id = :id', ['id' => $obligationId]);
            same(money($obligation['net_payable_amount']) === 0,
                'A previously paid supplier payable reopened: #' . $obligationId);
        }
        $after = ['supplier' => position($app), 'cash_cents' => cashPosition($db)];
        same($after['cash_cents'] - $before['cash_cents'] === DIFFERENCE * 100,
            'Cash-SWT-PKR did not rise by exactly PKR 75,277.');
        same(money($after['supplier']['net']) === money($before['supplier']['net'])
            && money($after['supplier']['payments']) === money($before['supplier']['payments']) - DIFFERENCE
            && money($after['supplier']['bills']) === money($before['supplier']['bills']) - DIFFERENCE,
            'Supplier account changed outside the approved payment and historical-adjustment swap.');
        $financialFields = static function (array $row, array $fields): array {
            return array_intersect_key($row, array_flip($fields));
        };
        $applicationFields = [
            'id', 'supplier_advance_id', 'supplier_obligation_id', 'applied_amount',
            'advance_amount_consumed', 'obligation_amount_applied', 'application_type', 'created_at',
        ];
        $auditId = AuditLog::recordRequired($app, 'supplier.payment.atomic_historical_correction', [
            'user_id' => $actorId, 'reference' => CORRECTION, 'supplier_id' => SUPPLIER,
            'payment_id' => 279,
            'payment_before' => $financialFields($payment, [
                'id', 'supplier_id', 'branch_id', 'payment_no', 'payment_date', 'currency',
                'paid_amount', 'allocated_amount', 'converted_advance_amount',
                'converted_advance_id', 'status', 'treasury_account_id',
            ]),
            'payment_after' => $paymentAfter,
            'advance_before' => array_map(static fn (array $row): array => $financialFields($row, [
                'id', 'supplier_id', 'branch_id', 'currency', 'deposit_amount',
                'available_amount', 'source_supplier_payment_id',
            ]), $advances),
            'application_before' => [
                'duplicate' => $financialFields($duplicate, $applicationFields),
                'targets' => array_map(
                    static fn (array $pair): array => $financialFields($pair[0], $applicationFields),
                    $replacementRows
                ),
                'existing_36' => $financialFields($already36, $applicationFields),
            ],
            'supplier_before' => $before['supplier'], 'supplier_after' => $after['supplier'],
            'cash_cents_before' => $before['cash_cents'], 'cash_cents_after' => $after['cash_cents'],
            'journal_ids' => $journalIds,
        ]);
        $result = $plan + [
            'journal_ids' => $journalIds, 'audit_id' => $auditId,
            'supplier_after' => $after['supplier'], 'cash_net_after' => $after['cash_cents'] / 100,
        ];
        if ($mode === 'dry-run') {
            $db->rollBack();
            $result['rolled_back'] = true;
        } else {
            $db->commit();
            $result['committed'] = true;
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    } finally {
        if ($lockHeld) {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote(CORRECTION) . ')');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'AirBlue atomic correction failed; transaction rolled back: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
