<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/CrossBranchSupplierAllocationAudit.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$hasAllocation567 = static function () use ($db): bool {
    foreach (CrossBranchSupplierAllocationAudit::failures($db, 100) as $failure) {
        if ((int) $failure['allocation_id'] === 567) {
            return true;
        }
    }
    return false;
};

try {
    if ($hasAllocation567()) {
        throw new RuntimeException('The fully evidenced multi-payment cancellation still fails audit.');
    }
    echo "PASS: The AED 2,097 cancellation release explains both original payment journals and the current AED 1 allocation.\n";

    // Negative control: an incorrect source credit must restore the audit failure.
    $db->beginTransaction();
    $db->exec('UPDATE supplier_advances SET deposit_amount = 1066 WHERE id = 85 AND deposit_amount = 1067');
    if (! $hasAllocation567()) {
        throw new RuntimeException('The audit accepted a mismatched supplier-credit source.');
    }
    $db->rollBack();
    echo "PASS: A broken AED 1 source-credit link is detected; the test change was rolled back.\n";

    if ($hasAllocation567()) {
        throw new RuntimeException('The test did not restore the original audited source.');
    }
    echo "PASS: Original cross-branch evidence remains intact after rollback.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
