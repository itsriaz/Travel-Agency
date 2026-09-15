<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/CrossBranchSupplierAllocationAudit.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Cross-branch supplier allocation audit regression (rollback only)' . PHP_EOL;

$currentFailures = CrossBranchSupplierAllocationAudit::failures($db, 100);
$check(
    'Current cross-branch global supplier allocations satisfy the strict evidence rule',
    $currentFailures === [],
    $currentFailures === [] ? '' : json_encode($currentFailures, JSON_UNESCAPED_SLASHES)
);

$fixture = $db->query(
    'SELECT spa.id AS allocation_id,
            sp.id AS payment_id,
            sp.payment_scope,
            sp.branch_id AS payment_branch_id,
            so.id AS obligation_id,
            so.branch_id AS obligation_branch_id,
            je.id AS allocation_journal_id
     FROM supplier_payment_allocations spa
     INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
     INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
     INNER JOIN journal_entry_lines jel
        ON jel.supplier_payment_id = sp.id
       AND jel.supplier_obligation_id = so.id
     INNER JOIN journal_entries je
        ON je.id = jel.journal_entry_id
       AND je.source_type = "supplier_payment_allocated"
     WHERE sp.status <> "void"
       AND sp.branch_id <> so.branch_id
     ORDER BY spa.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($fixture === false) {
    $check('A valid local cross-branch allocation exists for negative probes', false);
} else {
    $db->beginTransaction();
    try {
        $scopeUpdate = $db->prepare('UPDATE supplier_payments SET payment_scope = "booking" WHERE id = :id');
        $scopeUpdate->execute(['id' => (int) $fixture['payment_id']]);
        $scopeFailures = CrossBranchSupplierAllocationAudit::failures($db, 100);
        $detected = array_filter(
            $scopeFailures,
            static fn (array $row): bool => (int) ($row['allocation_id'] ?? 0) === (int) $fixture['allocation_id']
                && (string) ($row['failure_reason'] ?? '') === 'cross_branch_payment_is_not_global'
        );
        $check('A cross-branch allocation with booking scope is rejected', $detected !== []);
    } finally {
        $db->rollBack();
    }

    $db->beginTransaction();
    try {
        $journalUpdate = $db->prepare('UPDATE journal_entries SET branch_id = :branch_id WHERE id = :id');
        $journalUpdate->execute([
            'branch_id' => (int) $fixture['payment_branch_id'],
            'id' => (int) $fixture['allocation_journal_id'],
        ]);
        $journalFailures = CrossBranchSupplierAllocationAudit::failures($db, 100);
        $detected = array_filter(
            $journalFailures,
            static fn (array $row): bool => (int) ($row['allocation_id'] ?? 0) === (int) $fixture['allocation_id']
                && (string) ($row['failure_reason'] ?? '') === 'allocation_journal_missing_or_misattributed'
        );
        $check('A global allocation journal posted to the payment branch instead of the obligation branch is rejected', $detected !== []);
    } finally {
        $db->rollBack();
    }

    $restored = $db->prepare(
        'SELECT sp.payment_scope, je.branch_id AS journal_branch_id
         FROM supplier_payments sp
         INNER JOIN journal_entries je ON je.id = :journal_id
         WHERE sp.id = :payment_id'
    );
    $restored->execute([
        'journal_id' => (int) $fixture['allocation_journal_id'],
        'payment_id' => (int) $fixture['payment_id'],
    ]);
    $restoredState = $restored->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'Negative probes roll back without changing production-derived history',
        (string) ($restoredState['payment_scope'] ?? '') === (string) $fixture['payment_scope']
            && (int) ($restoredState['journal_branch_id'] ?? 0) === (int) $fixture['obligation_branch_id']
    );
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Cross-branch supplier allocation audit regression failed.' . PHP_EOL);
    exit(1);
}

echo 'Cross-branch supplier allocation audit regression passed; no fixture data was retained.' . PHP_EOL;
