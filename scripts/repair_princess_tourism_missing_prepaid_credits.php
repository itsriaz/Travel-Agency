<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\SupplierRepository;
use App\Services\SupplierSettlementWorkspaceService;

$apply = in_array('--apply', $argv, true);

$entries = [
    [
        'payment_date' => '2026-07-22',
        'currency' => 'AED',
        'amount' => 3000.00,
        'reference_no' => '17288',
        'remarks' => 'Legacy supplier statement import: Princess Tourism prepaid credit from client ledger ref 17288.',
    ],
    [
        'payment_date' => '2026-07-28',
        'currency' => 'AED',
        'amount' => 3000.00,
        'reference_no' => '17643',
        'remarks' => 'Legacy supplier statement import: Princess Tourism prepaid credit from client ledger ref 17643.',
    ],
];

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$supplierRepository = new SupplierRepository($app);
$accountingRepository = new AccountingRepository($app);
$settlementService = new SupplierSettlementWorkspaceService($app);

$branchIds = array_values(array_filter(array_map(
    'intval',
    $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []
)));
$supplier = $supplierRepository->findAccessibleSupplierByName('Princess Tourism', $branchIds);
if ($supplier === null) {
    fwrite(STDERR, "Princess Tourism supplier could not be resolved.\n");
    exit(1);
}

$supplierId = (int) ($supplier['id'] ?? 0);
$branchId = (int) ($supplier['branch_id'] ?? 0);
$actorUserId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();

if ($supplierId <= 0 || $branchId <= 0 || $actorUserId <= 0) {
    fwrite(STDERR, "Required supplier, branch, or actor context is missing.\n");
    exit(1);
}

echo "Princess Tourism missing prepaid-credit repair\n";
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Supplier ID: ' . $supplierId . PHP_EOL;
echo 'Branch ID: ' . $branchId . PHP_EOL;

$planned = [];
foreach ($entries as $entry) {
    $existsAdvance = $db->prepare(
        'SELECT id
         FROM supplier_advances
         WHERE supplier_id = :supplier_id
           AND currency = :currency
           AND received_at = :received_at
           AND ABS(deposit_amount - :deposit_amount) < 0.005
           AND COALESCE(reference_no, "") = :reference_no
         LIMIT 1'
    );
    $existsAdvance->execute([
        'supplier_id' => $supplierId,
        'currency' => $entry['currency'],
        'received_at' => $entry['payment_date'],
        'deposit_amount' => $entry['amount'],
        'reference_no' => $entry['reference_no'],
    ]);
    $existingAdvanceId = (int) ($existsAdvance->fetchColumn() ?: 0);

    if ($existingAdvanceId > 0) {
        echo sprintf(
            "[SKIP] %s %s on %s already exists as supplier advance #%d.\n",
            $entry['currency'],
            number_format((float) $entry['amount'], 2),
            $entry['payment_date'],
            $existingAdvanceId
        );
        continue;
    }

    $planned[] = $entry;
    echo sprintf(
        "[READY] add prepaid supplier credit %s %s dated %s (ref %s).\n",
        $entry['currency'],
        number_format((float) $entry['amount'], 2),
        $entry['payment_date'],
        $entry['reference_no']
    );
}

if ($planned === []) {
    echo "Nothing new to repair.\n";
    exit(0);
}

if (! $apply) {
    echo "Dry run complete. No data changed.\n";
    exit(0);
}

$createdAdvanceIds = [];
$createdJournalIds = [];
$appliedTotal = 0.0;
$applicationCount = 0;

$db->beginTransaction();
try {
    foreach ($planned as $entry) {
        $advanceId = $supplierRepository->registerAdvance([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'currency' => $entry['currency'],
            'deposit_amount' => $entry['amount'],
            'available_amount' => $entry['amount'],
            'reference_no' => $entry['reference_no'],
            'remarks' => $entry['remarks'],
            'received_at' => $entry['payment_date'],
            'payment_method' => 'bank_transfer',
            'treasury_account_id' => null,
            'journal_entry_id' => null,
            'actor_user_id' => $actorUserId,
            'source_supplier_payment_id' => null,
        ]);

        $journalEntryId = $accountingRepository->postSupplierAdvanceDeposit([
            'branch_id' => $branchId,
            'booking_reference' => null,
            'source_reference' => 'SADV-' . $advanceId,
            'entry_date' => $entry['payment_date'],
            'currency' => $entry['currency'],
            'amount' => $entry['amount'],
            'payment_method' => 'bank_transfer',
            'treasury_account_id' => null,
            'actor_user_id' => $actorUserId,
            'narration' => 'Legacy prepaid supplier credit imported from Princess Tourism statement',
        ]);
        $supplierRepository->attachAdvanceJournal($advanceId, $journalEntryId);

        $reconciliation = $settlementService->applyAvailableSupplierAdvancesToOpenPayables(
            $branchIds,
            $actorUserId,
            $supplierId,
            $entry['currency'],
            $entry['payment_date']
        );

        $createdAdvanceIds[] = $advanceId;
        $createdJournalIds[] = $journalEntryId;
        $appliedTotal = round($appliedTotal + (float) ($reconciliation['applied_amount'] ?? 0), 2);
        $applicationCount += (int) ($reconciliation['application_count'] ?? 0);
    }

    $db->commit();

    echo 'Created advances: ' . implode(', ', $createdAdvanceIds) . PHP_EOL;
    echo 'Created journals: ' . implode(', ', $createdJournalIds) . PHP_EOL;
    echo 'Applied to payables: ' . number_format($appliedTotal, 2) . PHP_EOL;
    echo 'Advance application rows posted: ' . $applicationCount . PHP_EOL;
    echo "Repair complete.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Repair failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
