<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

$apply = in_array('--apply', $argv ?? [], true);
$csv = in_array('--csv', $argv ?? [], true);
if (count(array_diff($argv ?? [], [$argv[0] ?? '', '--apply', '--csv'])) > 0 || ($apply && $csv)) {
    throw new InvalidArgumentException('Usage: php scripts/reconcile_legacy_supplier_allocation_journals.php [--apply|--csv]');
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
if ($apply) {
    // Read candidates and store evidence from one consistent database snapshot.
    $db->beginTransaction();
}

$rows = $db->query(
    'SELECT je.id AS journal_entry_id, jel.id AS journal_line_id,
            je.created_at AS journal_created_at, je.entry_date, je.branch_id, je.currency,
            je.source_reference, jel.supplier_payment_id, jel.supplier_obligation_id,
            jel.debit_amount AS allocated_amount,
            sp.payment_no, sp.supplier_id AS payment_supplier_id, sp.currency AS payment_currency,
            so.supplier_id AS obligation_supplier_id, so.branch_id AS obligation_branch_id,
            so.currency AS obligation_currency,
            COUNT(DISTINCT spa.id) AS candidate_count,
            MAX(spa.id) AS candidate_allocation_id,
            MAX(spa.allocated_at) AS candidate_created_at,
            totals.ap_debit, totals.ap_credit, totals.advance_debit, totals.advance_credit
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.code = "AP_CONTROL"
     LEFT JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
     LEFT JOIN supplier_obligations so ON so.id = jel.supplier_obligation_id
     LEFT JOIN supplier_payment_allocations spa
       ON spa.supplier_payment_id = jel.supplier_payment_id
      AND spa.supplier_obligation_id = jel.supplier_obligation_id
      AND ABS(spa.allocated_amount - jel.debit_amount) < 0.005
     INNER JOIN (
        SELECT journal_line.journal_entry_id,
            SUM(CASE WHEN account.code = "AP_CONTROL" THEN journal_line.debit_amount ELSE 0 END) AS ap_debit,
            SUM(CASE WHEN account.code = "AP_CONTROL" THEN journal_line.credit_amount ELSE 0 END) AS ap_credit,
            SUM(CASE WHEN account.code = "SUPPLIER_ADVANCES" THEN journal_line.debit_amount ELSE 0 END) AS advance_debit,
            SUM(CASE WHEN account.code = "SUPPLIER_ADVANCES" THEN journal_line.credit_amount ELSE 0 END) AS advance_credit
        FROM journal_entry_lines journal_line
        INNER JOIN chart_of_accounts account ON account.id = journal_line.account_id
        GROUP BY journal_line.journal_entry_id
     ) totals ON totals.journal_entry_id = je.id
     WHERE je.source_type = "supplier_payment_allocated"
       AND je.source_reference REGEXP "-(ALLOC|REALLOC)-0$"
     GROUP BY je.id, jel.id, je.created_at, je.entry_date, je.branch_id, je.currency,
              je.source_reference, jel.supplier_payment_id, jel.supplier_obligation_id,
              jel.debit_amount, sp.payment_no, sp.supplier_id, sp.currency,
              so.supplier_id, so.branch_id, so.currency,
              totals.ap_debit, totals.ap_credit, totals.advance_debit, totals.advance_credit
     ORDER BY jel.id'
)->fetchAll(PDO::FETCH_ASSOC);

$candidateUse = [];
foreach ($rows as $row) {
    if ((int) $row['candidate_count'] === 1 && (int) $row['candidate_allocation_id'] > 0) {
        $candidateUse[(int) $row['candidate_allocation_id']] =
            ($candidateUse[(int) $row['candidate_allocation_id']] ?? 0) + 1;
    }
}

$classify = static function (array $row) use ($candidateUse): array {
    $amount = (float) $row['allocated_amount'];
    $balanced = $amount > 0.005
        && abs((float) $row['ap_debit'] - $amount) <= 0.005
        && abs((float) $row['ap_credit']) <= 0.005
        && abs((float) $row['advance_debit']) <= 0.005
        && abs((float) $row['advance_credit'] - $amount) <= 0.005;
    $dimensions = (int) $row['supplier_payment_id'] > 0
        && (int) $row['supplier_obligation_id'] > 0
        && (int) $row['payment_supplier_id'] > 0
        && (int) $row['payment_supplier_id'] === (int) $row['obligation_supplier_id']
        && (int) $row['branch_id'] === (int) $row['obligation_branch_id']
        && (string) $row['currency'] === (string) $row['payment_currency']
        && (string) $row['currency'] === (string) $row['obligation_currency'];
    $paymentReference = (string) ($row['payment_no'] ?? '');
    $referenceMatches = $paymentReference !== ''
        && str_starts_with((string) $row['source_reference'], $paymentReference . '-');
    $candidateId = (int) $row['candidate_allocation_id'];
    if (! $balanced) {
        $reason = 'journal_amount_or_sides_invalid';
    } elseif (! $dimensions) {
        $reason = 'current_source_dimensions_differ';
    } elseif (! $referenceMatches) {
        $reason = 'payment_reference_mismatch';
    } elseif ((int) $row['candidate_count'] !== 1) {
        $reason = (int) $row['candidate_count'] === 0 ? 'no_current_allocation' : 'multiple_current_allocations';
    } elseif (($candidateUse[$candidateId] ?? 0) !== 1) {
        $reason = 'candidate_shared_by_multiple_journals';
    } elseif ((string) $row['journal_created_at'] !== (string) $row['candidate_created_at']) {
        $reason = 'allocation_created_at_different_time';
    } else {
        $reason = 'same_second_unique_source_and_balanced_journal';
    }

    return [
        'status' => $reason === 'same_second_unique_source_and_balanced_journal'
            ? 'time_correlated_candidate' : 'unresolved',
        'reason' => $reason,
        'candidate_id' => $reason === 'same_second_unique_source_and_balanced_journal' ? $candidateId : null,
    ];
};

$summary = [];
$classified = [];
foreach ($rows as $row) {
    $decision = $classify($row);
    $summary[$decision['reason']] = ($summary[$decision['reason']] ?? 0) + 1;
    $classified[] = [$row, $decision];
}
ksort($summary);

if ($csv) {
    fputcsv(STDOUT, [
        'journal_entry_id', 'journal_line_id', 'source_reference',
        'supplier_payment_id', 'supplier_obligation_id', 'allocated_amount',
        'journal_created_at', 'candidate_created_at', 'candidate_allocation_id',
        'evidence_status', 'evidence_reason',
    ]);
    foreach ($classified as [$row, $decision]) {
        fputcsv(STDOUT, [
            $row['journal_entry_id'], $row['journal_line_id'], $row['source_reference'],
            $row['supplier_payment_id'], $row['supplier_obligation_id'], $row['allocated_amount'],
            $row['journal_created_at'], $row['candidate_created_at'], $decision['candidate_id'],
            $decision['status'], $decision['reason'],
        ]);
    }
    exit(0);
}

if ($apply) {
    $previouslyRecordedWithChangedCurrentSource = 0;
    try {
        $find = $db->prepare('SELECT journal_entry_id, supplier_payment_id, supplier_obligation_id,
                allocated_amount, evidence_status, evidence_reason, candidate_allocation_id
            FROM supplier_allocation_journal_evidence WHERE journal_line_id = :journal_line_id FOR UPDATE');
        $insert = $db->prepare('INSERT INTO supplier_allocation_journal_evidence
            (journal_line_id, journal_entry_id, supplier_payment_id, supplier_obligation_id,
             allocated_amount, candidate_allocation_id, evidence_status, evidence_reason, evidence_json)
            VALUES (:journal_line_id, :journal_entry_id, :supplier_payment_id, :supplier_obligation_id,
                    :allocated_amount, :candidate_allocation_id, :evidence_status, :evidence_reason, :evidence_json)');
        foreach ($classified as [$row, $decision]) {
            $find->execute(['journal_line_id' => $row['journal_line_id']]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                if ((int) $existing['journal_entry_id'] !== (int) $row['journal_entry_id']
                    || (int) $existing['supplier_payment_id'] !== (int) $row['supplier_payment_id']
                    || (int) $existing['supplier_obligation_id'] !== (int) $row['supplier_obligation_id']
                    || abs((float) $existing['allocated_amount'] - (float) $row['allocated_amount']) > 0.005) {
                    throw new RuntimeException('Original journal evidence changed for line ' . $row['journal_line_id'] . '.');
                }
                if ($existing['evidence_status'] !== $decision['status']
                    || $existing['evidence_reason'] !== $decision['reason']
                    || (int) $existing['candidate_allocation_id'] !== (int) $decision['candidate_id']) {
                    // A later valid correction can remove the live allocation. Its
                    // original evidence remains immutable and must not be rewritten.
                    $previouslyRecordedWithChangedCurrentSource++;
                }
                continue;
            }
            $insert->execute([
                'journal_line_id' => $row['journal_line_id'],
                'journal_entry_id' => $row['journal_entry_id'],
                'supplier_payment_id' => $row['supplier_payment_id'],
                'supplier_obligation_id' => $row['supplier_obligation_id'],
                'allocated_amount' => $row['allocated_amount'],
                'candidate_allocation_id' => $decision['candidate_id'],
                'evidence_status' => $decision['status'],
                'evidence_reason' => $decision['reason'],
                'evidence_json' => json_encode([
                    'source_reference' => $row['source_reference'],
                    'entry_date' => $row['entry_date'],
                    'journal_created_at' => $row['journal_created_at'],
                    'candidate_created_at' => $row['candidate_created_at'],
                    'candidate_count' => (int) $row['candidate_count'],
                    'journal_branch_id' => (int) $row['branch_id'],
                    'journal_currency' => $row['currency'],
                    'ap_debit' => $row['ap_debit'],
                    'advance_credit' => $row['advance_credit'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

echo 'Historical zero-ID AP journal lines: ' . count($rows) . PHP_EOL;
foreach ($summary as $reason => $count) {
    echo $reason . ': ' . $count . PHP_EOL;
}
echo $apply
    ? 'Evidence recorded locally; original journals, allocation rows, and balances were not changed.' . PHP_EOL
    : 'Dry run only; no database rows changed.' . PHP_EOL;
if ($apply && $previouslyRecordedWithChangedCurrentSource > 0) {
    echo 'Previously recorded evidence with changed current allocation state: '
        . $previouslyRecordedWithChangedCurrentSource . PHP_EOL;
}
