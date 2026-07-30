<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;
use App\Services\SupplierSettlementWorkspaceService;

$app = (isset($app) && $app instanceof App) ? $app : App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Supplier advance reconciles existing payables regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) ($db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$actorUserId = (int) ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$check('Active branch and user are available', $branchId > 0 && $actorUserId > 0);
if ($branchId <= 0 || $actorUserId <= 0) {
    return 1;
}

$db->beginTransaction();

try {
    $suffix = date('YmdHis') . '-' . random_int(1000, 9999);
    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active, notes)
         VALUES (:branch_id, :code, :name, "normal_payable", "AED", 1, :notes)'
    );
    $insertSupplier->execute([
        'branch_id' => $branchId,
        'code' => 'REG-ADV-EXIST-' . $suffix,
        'name' => 'REG Existing Payable Advance ' . $suffix,
        'notes' => 'Rollback-only supplier advance reconciliation regression.',
    ]);
    $supplierId = (int) $db->lastInsertId();

    $insertObligation = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
            gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, :service_line_reference, "service_cost", "AED",
            :gross_amount, 0.00, :net_payable_amount, :due_date, "open", :remarks, :actor_user_id
         )'
    );
    foreach ([
        ['reference' => 'REG-BK-ADV-A-' . $suffix, 'line' => 'SV-001', 'amount' => 400.00],
        ['reference' => 'REG-BK-ADV-B-' . $suffix, 'line' => 'SV-001', 'amount' => 600.00],
    ] as $fixture) {
        $insertObligation->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'booking_reference' => $fixture['reference'],
            'service_line_reference' => $fixture['line'],
            'gross_amount' => $fixture['amount'],
            'net_payable_amount' => $fixture['amount'],
            'due_date' => date('Y-m-d'),
            'remarks' => 'Existing payable created before the supplier advance.',
            'actor_user_id' => $actorUserId,
        ]);
    }

    $repository = new SupplierRepository($app);
    $advanceId = $repository->registerAdvance([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'currency' => 'AED',
        'deposit_amount' => 600.00,
        'available_amount' => 600.00,
        'reference_no' => 'REG-SADV-' . $suffix,
        'remarks' => 'Advance created after open payables for reconciliation testing.',
        'received_at' => date('Y-m-d'),
        'actor_user_id' => $actorUserId,
    ]);

    $paymentCountBefore = (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn();
    $result = (new SupplierSettlementWorkspaceService($app))->applyAvailableSupplierAdvancesToOpenPayables(
        [$branchId],
        $actorUserId,
        $supplierId,
        'AED',
        date('Y-m-d')
    );

    $obligations = $db->query(
        'SELECT gross_amount, advance_applied_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE supplier_id = ' . $supplierId . '
         ORDER BY booking_reference'
    )->fetchAll() ?: [];
    $advance = $repository->findAdvanceById($advanceId) ?? [];
    $applicationTotal = (float) $db->query(
        'SELECT COALESCE(SUM(aa.applied_amount), 0)
         FROM supplier_advance_applications aa
         INNER JOIN supplier_obligations o ON o.id = aa.supplier_obligation_id
         WHERE o.supplier_id = ' . $supplierId
    )->fetchColumn();
    $paymentCountAfter = (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn();

    $check(
        'Available advance is applied oldest-first across existing payables',
        abs((float) ($result['applied_amount'] ?? 0) - 600.00) < 0.005
            && (int) ($result['application_count'] ?? 0) === 2
            && count($obligations) === 2
            && abs((float) ($obligations[0]['net_payable_amount'] ?? 0)) < 0.005
            && abs((float) ($obligations[1]['net_payable_amount'] ?? 0) - 400.00) < 0.005,
        json_encode(['result' => $result, 'obligations' => $obligations], JSON_THROW_ON_ERROR)
    );
    $check(
        'Advance and application records conserve value',
        abs((float) ($advance['deposit_amount'] ?? 0) - 600.00) < 0.005
            && abs((float) ($advance['available_amount'] ?? 0)) < 0.005
            && abs($applicationTotal - 600.00) < 0.005,
        json_encode(['advance' => $advance, 'application_total' => $applicationTotal], JSON_THROW_ON_ERROR)
    );
    $check(
        'Reconciliation creates no second supplier payment or cash movement',
        $paymentCountAfter === $paymentCountBefore,
        json_encode(['before' => $paymentCountBefore, 'after' => $paymentCountAfter], JSON_THROW_ON_ERROR)
    );

    $journalRows = $db->query(
        'SELECT je.id,
                ROUND(SUM(jl.debit_amount), 2) AS debit_total,
                ROUND(SUM(jl.credit_amount), 2) AS credit_total
         FROM journal_entries je
         INNER JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id
         WHERE je.source_type = "supplier_advance_applied"
           AND je.source_reference LIKE "SADV-AUTO-%"
           AND je.booking_reference LIKE "REG-BK-ADV-%-' . $suffix . '"
         GROUP BY je.id'
    )->fetchAll() ?: [];
    $journalsBalanced = count($journalRows) === 2;
    foreach ($journalRows as $journalRow) {
        $journalsBalanced = $journalsBalanced
            && abs((float) ($journalRow['debit_total'] ?? 0) - (float) ($journalRow['credit_total'] ?? 0)) < 0.005;
    }
    $check(
        'Each advance application posts a balanced non-cash journal',
        $journalsBalanced,
        json_encode($journalRows, JSON_THROW_ON_ERROR)
    );

    $secondResult = (new SupplierSettlementWorkspaceService($app))->applyAvailableSupplierAdvancesToOpenPayables(
        [$branchId],
        $actorUserId,
        $supplierId,
        'AED',
        date('Y-m-d')
    );
    $check(
        'Reconciliation is idempotent',
        abs((float) ($secondResult['applied_amount'] ?? 0)) < 0.005
            && (int) ($secondResult['application_count'] ?? 0) === 0,
        json_encode($secondResult, JSON_THROW_ON_ERROR)
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier advance existing-payables reconciliation regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Supplier advance existing-payables reconciliation regression passed.' . PHP_EOL;
return 0;
