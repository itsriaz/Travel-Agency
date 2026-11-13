<?php

declare(strict_types=1);

// Rollback-only report-impact check. Never commits a historical snapshot backfill.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$auditPath = BASE_PATH . '/storage/supplier-journal-snapshot-audit-20261025.json';
$audit = json_decode((string) file_get_contents($auditPath), true, 512, JSON_THROW_ON_ERROR);
$candidates = $audit['candidates'] ?? [];
if (($audit['mode'] ?? '') !== 'read_only' || count($candidates) !== 6612) {
    throw new RuntimeException('Audit candidate set changed; review it before rehearsal.');
}
if (in_array('--include-timeline', $argv, true)) {
    $timeline = json_decode((string) file_get_contents(
        BASE_PATH . '/storage/supplier-journal-snapshot-timeline-20261025.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    if (($timeline['mode'] ?? '') !== 'read_only'
        || (int) ($timeline['resolved_count'] ?? 0) !== 512
        || (int) ($timeline['unresolved_count'] ?? 0) !== 4) {
        throw new RuntimeException('Timeline resolution changed; review it before rehearsal.');
    }
    $candidates = array_merge($candidates, $timeline['resolved']);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$ledger = new \App\Services\SupplierLedgerService($app);
$capture = static function () use ($ledger, $branches): array {
    $out = [];
    $scopes = ['all_branches' => $branches];
    foreach ($branches as $branchId) {
        $scopes['branch_' . $branchId] = [$branchId];
    }
    foreach ($scopes as $name => $ids) {
        $report = $ledger->report([], $ids);
        $out[$name] = [];
        foreach ($report['rows'] as $row) {
            $key = (string) ($row['supplier_name'] ?? '') . '|' . (string) ($row['currency'] ?? '');
            $out[$name][$key] ??= ['rows' => 0, 'debit' => 0.0, 'credit' => 0.0];
            $out[$name][$key]['rows']++;
            $out[$name][$key]['debit'] = round($out[$name][$key]['debit']
                + (float) ($row['raw_debit_amount'] ?? 0), 2);
            $out[$name][$key]['credit'] = round($out[$name][$key]['credit']
                + (float) ($row['raw_credit_amount'] ?? 0), 2);
        }
        ksort($out[$name]);
    }
    return $out;
};

$before = $capture();
$db->beginTransaction();
try {
    $update = $db->prepare('UPDATE journal_entry_lines SET supplier_id_snapshot = :supplier_id
        WHERE id = :line_id AND supplier_id_snapshot IS NULL');
    foreach ($candidates as $candidate) {
        $update->execute([
            'supplier_id' => (int) $candidate['supplier_id'],
            'line_id' => (int) $candidate['journal_line_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Candidate no longer matches line ' . $candidate['journal_line_id']);
        }
    }
    $after = $capture();
    $differences = [];
    foreach ($before as $scope => $positions) {
        foreach (array_unique(array_merge(array_keys($positions), array_keys($after[$scope] ?? []))) as $key) {
            if (($positions[$key] ?? null) !== ($after[$scope][$key] ?? null)) {
                $differences[] = [
                    'scope' => $scope,
                    'supplier_currency' => $key,
                    'before' => $positions[$key] ?? null,
                    'after' => $after[$scope][$key] ?? null,
                ];
            }
        }
    }
    echo json_encode([
        'mode' => 'rollback_only',
        'candidates_rehearsed' => count($candidates),
        'supplier_ledger_differences' => $differences,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $db->rollBack();
}
