<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$paymentNo = 'SPAY-000113';
$releasePrefix = 'SUPPLIER-PAYMENT-CORRECTION-' . $paymentNo . '-RELEASE-';
$voidReference = 'VOID-' . $paymentNo;

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$paymentStatement = $db->prepare(
    'SELECT id, branch_id, supplier_id, payment_no, currency, paid_amount, status, created_by_user_id
     FROM supplier_payments
     WHERE payment_no = :payment_no
     LIMIT 1'
);
$paymentStatement->execute(['payment_no' => $paymentNo]);
$payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($payment)) {
    throw new RuntimeException($paymentNo . ' was not found. No changes were made.');
}
if ((string) ($payment['status'] ?? '') !== 'void'
    || strtoupper((string) ($payment['currency'] ?? '')) !== 'PKR'
    || abs((float) ($payment['paid_amount'] ?? 0) - 116548) > 0.005) {
    throw new RuntimeException($paymentNo . ' does not match the reviewed void PKR 116548 payment fingerprint. No changes were made.');
}

$lineStatement = $db->prepare(
    'SELECT je.id AS journal_entry_id, je.branch_id, je.booking_reference, je.source_type,
            je.source_reference, je.entry_date, je.currency, coa.code AS account_code,
            jel.service_line_reference, jel.supplier_obligation_id, jel.supplier_payment_id,
            jel.line_description, jel.debit_amount, jel.credit_amount
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE je.source_reference LIKE :release_reference
        OR je.source_reference = :void_reference
     ORDER BY je.id, jel.id'
);
$lineStatement->execute([
    'release_reference' => $releasePrefix . '%',
    'void_reference' => $voidReference,
]);
$sourceRows = $lineStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$releaseJournals = [];
$voidRows = [];
foreach ($sourceRows as $row) {
    if ((string) ($row['source_type'] ?? '') === 'supplier_settlement_released'
        && str_starts_with((string) ($row['source_reference'] ?? ''), $releasePrefix)) {
        $releaseJournals[(int) $row['journal_entry_id']][] = $row;
    }
    if ((string) ($row['source_type'] ?? '') === 'supplier_payment_void_reversed'
        && (string) ($row['source_reference'] ?? '') === $voidReference) {
        $voidRows[] = $row;
    }
}

if (count($releaseJournals) !== 2) {
    throw new RuntimeException('Expected exactly two reviewed duplicate release journals for ' . $paymentNo . '. No changes were made.');
}

$releaseTotal = 0.0;
foreach ($releaseJournals as $journalId => $rows) {
    $debit = round(array_sum(array_column($rows, 'debit_amount')), 2);
    $credit = round(array_sum(array_column($rows, 'credit_amount')), 2);
    $accounts = array_values(array_unique(array_column($rows, 'account_code')));
    if (count($rows) !== 2
        || abs($debit - $credit) > 0.005
        || ! in_array('AP_CONTROL', $accounts, true)
        || ! in_array('SUPPLIER_ADVANCES', $accounts, true)) {
        throw new RuntimeException('Release journal ' . $journalId . ' no longer matches the reviewed accounting fingerprint. No changes were made.');
    }
    $releaseTotal = round($releaseTotal + $debit, 2);
}
if (abs($releaseTotal - 116548) > 0.005) {
    throw new RuntimeException('Reviewed duplicate release total is not PKR 116548. No changes were made.');
}

$mismatchedVoidGroups = [];
foreach ($voidRows as $row) {
    $obligationId = (int) ($row['supplier_obligation_id'] ?? 0);
    if ($obligationId <= 0) {
        continue;
    }
    $obligationStatement = $db->prepare(
        'SELECT branch_id, booking_reference, currency
         FROM supplier_obligations WHERE id = :id LIMIT 1'
    );
    $obligationStatement->execute(['id' => $obligationId]);
    $obligation = $obligationStatement->fetch(PDO::FETCH_ASSOC);
    if (! is_array($obligation)) {
        throw new RuntimeException('Supplier obligation ' . $obligationId . ' linked to the void journal was not found.');
    }
    if ((int) ($row['branch_id'] ?? 0) === (int) ($obligation['branch_id'] ?? 0)) {
        continue;
    }
    $groupKey = (int) $row['journal_entry_id'] . '|' . $obligationId;
    if (! isset($mismatchedVoidGroups[$groupKey])) {
        $mismatchedVoidGroups[$groupKey] = [
            'journal_entry_id' => (int) $row['journal_entry_id'],
            'wrong_branch_id' => (int) $row['branch_id'],
            'target_branch_id' => (int) $obligation['branch_id'],
            'booking_reference' => (string) $obligation['booking_reference'],
            'currency' => (string) $obligation['currency'],
            'supplier_obligation_id' => $obligationId,
            'rows' => [],
        ];
    }
    $mismatchedVoidGroups[$groupKey]['rows'][] = $row;
}

if (count($mismatchedVoidGroups) !== 1) {
    throw new RuntimeException('Expected exactly one reviewed cross-branch void-reversal group for ' . $paymentNo . '. No changes were made.');
}

foreach ($mismatchedVoidGroups as $group) {
    $debit = round(array_sum(array_column($group['rows'], 'debit_amount')), 2);
    $credit = round(array_sum(array_column($group['rows'], 'credit_amount')), 2);
    $accounts = array_values(array_unique(array_column($group['rows'], 'account_code')));
    if (count($group['rows']) !== 2
        || abs($debit - 96542) > 0.005
        || abs($credit - 96542) > 0.005
        || ! in_array('AP_CONTROL', $accounts, true)
        || ! in_array('SUPPLIER_ADVANCES', $accounts, true)) {
        throw new RuntimeException('Cross-branch void reversal no longer matches the reviewed PKR 96542 fingerprint. No changes were made.');
    }
}

$repairReferences = [];
foreach (array_keys($releaseJournals) as $journalId) {
    $repairReferences[] = 'DATA-REPAIR-REVERSE-JE-' . $journalId;
}
foreach ($mismatchedVoidGroups as $group) {
    $suffix = '-JE-' . $group['journal_entry_id'] . '-O-' . $group['supplier_obligation_id'];
    $repairReferences[] = 'DATA-REPAIR-VOID-BRANCH-OUT' . $suffix;
    $repairReferences[] = 'DATA-REPAIR-VOID-BRANCH-IN' . $suffix;
}

$existingRepairStatement = $db->prepare(
    'SELECT id, source_reference FROM journal_entries WHERE source_reference = :source_reference LIMIT 1'
);
$existingRepairs = [];
foreach ($repairReferences as $repairReference) {
    $existingRepairStatement->execute(['source_reference' => $repairReference]);
    $existing = $existingRepairStatement->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        $existingRepairs[$repairReference] = (int) $existing['id'];
    }
}
if ($existingRepairs !== [] && count($existingRepairs) !== count($repairReferences)) {
    throw new RuntimeException('A partial ' . $paymentNo . ' repair already exists. Stop for manual review; no changes were made.');
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'payment_no' => $paymentNo,
    'payment_id' => (int) $payment['id'],
    'payment_branch_id' => (int) $payment['branch_id'],
    'currency' => (string) $payment['currency'],
    'duplicate_release_journals' => array_map(
        static function (array $rows, int $journalId): array {
            return [
                'journal_entry_id' => $journalId,
                'branch_id' => (int) ($rows[0]['branch_id'] ?? 0),
                'booking_reference' => (string) ($rows[0]['booking_reference'] ?? ''),
                'amount' => round(array_sum(array_column($rows, 'debit_amount')), 2),
            ];
        },
        array_values($releaseJournals),
        array_keys($releaseJournals)
    ),
    'void_branch_reclassifications' => array_map(
        static fn (array $group): array => [
            'journal_entry_id' => $group['journal_entry_id'],
            'supplier_obligation_id' => $group['supplier_obligation_id'],
            'booking_reference' => $group['booking_reference'],
            'from_branch_id' => $group['wrong_branch_id'],
            'to_branch_id' => $group['target_branch_id'],
            'amount' => round(array_sum(array_column($group['rows'], 'debit_amount')), 2),
        ],
        array_values($mismatchedVoidGroups)
    ),
    'changes_to_operational_records' => false,
    'changes_to_treasury_cash' => false,
    'already_applied' => count($existingRepairs) === count($repairReferences),
    'repair_journal_ids' => $existingRepairs,
];

if (! $apply || $report['already_applied']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$accounting = new \App\Repositories\AccountingRepository($app);
$createdJournalIds = [];
$actorUserId = (int) ($payment['created_by_user_id'] ?? 0);

$db->beginTransaction();
try {
    foreach ($releaseJournals as $journalId => $rows) {
        $sourceReference = 'DATA-REPAIR-REVERSE-JE-' . $journalId;
        $lines = array_map(static fn (array $row): array => [
            'account_code' => (string) $row['account_code'],
            'service_line_reference' => ($row['service_line_reference'] ?? null) ?: null,
            'supplier_obligation_id' => (int) ($row['supplier_obligation_id'] ?? 0) ?: null,
            'supplier_payment_id' => (int) ($row['supplier_payment_id'] ?? 0) ?: null,
            'line_description' => 'Data repair reversal of duplicate supplier payment correction release journal ' . $journalId,
            'debit_amount' => round((float) ($row['credit_amount'] ?? 0), 2),
            'credit_amount' => round((float) ($row['debit_amount'] ?? 0), 2),
        ], $rows);
        $createdJournalIds[$sourceReference] = $accounting->postJournalEntry([
            'branch_id' => (int) $rows[0]['branch_id'],
            'booking_reference' => (string) ($rows[0]['booking_reference'] ?? '') ?: null,
            'source_type' => 'data_repair_journal_reversal',
            'source_reference' => $sourceReference,
            'entry_date' => date('Y-m-d'),
            'currency' => (string) $rows[0]['currency'],
            'narration' => 'Neutralize duplicate supplier payment correction release for ' . $paymentNo,
            'actor_user_id' => $actorUserId ?: null,
        ], $lines);
    }

    foreach ($mismatchedVoidGroups as $group) {
        $suffix = '-JE-' . $group['journal_entry_id'] . '-O-' . $group['supplier_obligation_id'];
        $outReference = 'DATA-REPAIR-VOID-BRANCH-OUT' . $suffix;
        $inReference = 'DATA-REPAIR-VOID-BRANCH-IN' . $suffix;
        $outLines = [];
        $inLines = [];
        foreach ($group['rows'] as $row) {
            $base = [
                'account_code' => (string) $row['account_code'],
                'service_line_reference' => ($row['service_line_reference'] ?? null) ?: null,
                'supplier_obligation_id' => (int) $group['supplier_obligation_id'],
                'supplier_payment_id' => (int) ($row['supplier_payment_id'] ?? 0) ?: null,
            ];
            $outLines[] = array_merge($base, [
                'line_description' => 'Remove misbranched supplier payment void reversal from branch ' . $group['wrong_branch_id'],
                'debit_amount' => round((float) ($row['credit_amount'] ?? 0), 2),
                'credit_amount' => round((float) ($row['debit_amount'] ?? 0), 2),
            ]);
            $inLines[] = array_merge($base, [
                'line_description' => 'Restore supplier payment void reversal in obligation branch ' . $group['target_branch_id'],
                'debit_amount' => round((float) ($row['debit_amount'] ?? 0), 2),
                'credit_amount' => round((float) ($row['credit_amount'] ?? 0), 2),
            ]);
        }
        $createdJournalIds[$outReference] = $accounting->postJournalEntry([
            'branch_id' => (int) $group['wrong_branch_id'],
            'booking_reference' => null,
            'source_type' => 'data_repair_branch_reclassification',
            'source_reference' => $outReference,
            'entry_date' => date('Y-m-d'),
            'currency' => (string) $group['currency'],
            'narration' => 'Remove misbranched void reversal for ' . $paymentNo,
            'actor_user_id' => $actorUserId ?: null,
        ], $outLines);
        $createdJournalIds[$inReference] = $accounting->postJournalEntry([
            'branch_id' => (int) $group['target_branch_id'],
            'booking_reference' => (string) $group['booking_reference'],
            'source_type' => 'data_repair_branch_reclassification',
            'source_reference' => $inReference,
            'entry_date' => date('Y-m-d'),
            'currency' => (string) $group['currency'],
            'narration' => 'Restore void reversal in obligation branch for ' . $paymentNo,
            'actor_user_id' => $actorUserId ?: null,
        ], $inLines);
    }

    \App\Helpers\AuditLog::record($app, 'data_repair.supplier_payment_cross_branch_correction', [
        'user_id' => $actorUserId ?: null,
        'supplier_payment_id' => (int) $payment['id'],
        'payment_no' => $paymentNo,
        'duplicate_release_total' => $releaseTotal,
        'repair_journal_ids' => $createdJournalIds,
        'operational_records_changed' => false,
        'treasury_cash_changed' => false,
    ]);

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

$report['repair_journal_ids'] = $createdJournalIds;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
