<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

$settlementService = new \App\Services\SupplierSettlementWorkspaceService($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Service supplier advance sweep after correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) ($db->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($branchId <= 0) {
    $check('At least one branch exists', false);
    return 1;
}

$today = date('Y-m-d');
$suffix = date('YmdHis') . '-' . random_int(1000, 9999);

$db->beginTransaction();

try {
    $supplierCode = 'REG-SWEEP-' . $suffix;
    $supplierName = 'REG Sweep Supplier ' . $suffix;
    $db->exec(sprintf(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, notes, is_active)
         VALUES (%d, %s, %s, %s, %s, %s, 1)',
        $branchId,
        $db->quote($supplierCode),
        $db->quote($supplierName),
        $db->quote('normal_payable'),
        $db->quote('AED'),
        $db->quote('Regression supplier for advance sweep after correction.')
    ));
    $supplierId = (int) $db->lastInsertId();

    $db->exec(sprintf(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
            gross_amount, advance_applied_amount, net_payable_amount, status, remarks, created_by_user_id
         ) VALUES (
            %d, %d, %s, %s, %s, %s,
            300.00, 0.00, 300.00, %s, %s, 1
         )',
        $supplierId,
        $branchId,
        $db->quote('REG-BK-PKR-' . $suffix),
        $db->quote('SV-PKR'),
        $db->quote('service_cost'),
        $db->quote('PKR'),
        $db->quote('open'),
        $db->quote('Corrected invoice stays in PKR.')
    ));

    $db->exec(sprintf(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
            gross_amount, advance_applied_amount, net_payable_amount, status, remarks, created_by_user_id
         ) VALUES (
            %d, %d, %s, %s, %s, %s,
            100.00, 0.00, 100.00, %s, %s, 1
         )',
        $supplierId,
        $branchId,
        $db->quote('REG-BK-AED-' . $suffix),
        $db->quote('SV-AED'),
        $db->quote('service_cost'),
        $db->quote('AED'),
        $db->quote('open'),
        $db->quote('Older AED invoice that should absorb the advance.')
    ));

    $db->exec(sprintf(
        'INSERT INTO supplier_advances (
            supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no,
            remarks, received_at, created_by_user_id
         ) VALUES (
            %d, %d, %s, 400.00, 400.00, %s,
            %s, %s, 1
         )',
        $supplierId,
        $branchId,
        $db->quote('AED'),
        $db->quote('REG-ADV-' . $suffix),
        $db->quote('Advance before correction sweep.'),
        $db->quote($today)
    ));
    $advanceId = (int) $db->lastInsertId();

    $result = $settlementService->applyAvailableSupplierAdvancesToOpenPayables(
        [$branchId],
        1,
        $supplierId,
        'AED',
        $today
    );

    $pkrObligation = $db->query(
        'SELECT currency, gross_amount, advance_applied_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE supplier_id = ' . $supplierId . '
           AND service_line_reference = "SV-PKR"
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $aedObligation = $db->query(
        'SELECT currency, gross_amount, advance_applied_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE supplier_id = ' . $supplierId . '
           AND service_line_reference = "SV-AED"
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $advance = $db->query(
        'SELECT deposit_amount, available_amount
         FROM supplier_advances
         WHERE id = ' . $advanceId
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $check(
        'Currency-corrected PKR invoice remains untouched by AED advance sweep',
        ($pkrObligation['currency'] ?? '') === 'PKR'
        && abs((float) ($pkrObligation['advance_applied_amount'] ?? 0)) <= 0.005
        && abs((float) ($pkrObligation['net_payable_amount'] ?? 0) - 300.00) <= 0.005
        && (string) ($pkrObligation['status'] ?? '') === 'open',
        json_encode($pkrObligation, JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Older AED invoice absorbs the available AED advance',
        ($aedObligation['currency'] ?? '') === 'AED'
        && abs((float) ($aedObligation['advance_applied_amount'] ?? 0) - 100.00) <= 0.005
        && abs((float) ($aedObligation['net_payable_amount'] ?? 0)) <= 0.005
        && (string) ($aedObligation['status'] ?? '') === 'covered_by_advance',
        json_encode($aedObligation, JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Remaining AED advance stays available for future AED invoices',
        abs((float) ($advance['deposit_amount'] ?? 0) - 400.00) <= 0.005
        && abs((float) ($advance['available_amount'] ?? 0) - 300.00) <= 0.005
        && abs((float) ($result['applied_amount'] ?? 0) - 100.00) <= 0.005,
        json_encode([
            'advance' => $advance,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES)
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] Regression execution - ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Service supplier advance sweep after correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Service supplier advance sweep after correction regression passed.' . PHP_EOL;
return 0;
