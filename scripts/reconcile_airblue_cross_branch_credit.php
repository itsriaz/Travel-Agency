<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
require BASE_PATH . '/tests/support/CrossBranchSupplierAllocationAudit.php';

use App\Core\App;
use App\Repositories\SupplierRepository;
use App\Services\SupplierAccountBalanceService;
use App\Services\SupplierSettlementWorkspaceService;

try {
    foreach ([
        'app/Repositories/SupplierRepository.php' => 'supplier_payment_credit_applications',
        'app/Repositories/ReportRepository.php' => 'Existing supplier payment credit applied against payable',
        'app/Services/SupplierLedgerService.php' => 'Existing supplier payment credit applied against payable',
        'app/Services/CommercialObligationSyncService.php' => 'Existing supplier payment credit applied against payable',
        'app/Services/SupplierSettlementWorkspaceService.php' => 'Existing supplier payment credit applied against payable',
    ] as $file => $requiredText) {
        if (!str_contains((string) file_get_contents(BASE_PATH . '/' . $file), $requiredText)) {
            throw new RuntimeException('Upload the updated application files before running this reconciliation: ' . $file);
        }
    }

    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $repo = new SupplierRepository($app);
    $balanceService = new SupplierAccountBalanceService($app);
    $branches = $balanceService->allBranchIds();
    $actorId = (int) $db->query('SELECT id FROM users WHERE username = "superadmin" AND is_active = 1 LIMIT 1')->fetchColumn();
    $payment = $repo->findSupplierPaymentById(247);
    $obligation = $repo->findObligationById(1170);
    if ($actorId <= 0
        || ($payment['payment_no'] ?? '') !== 'SPAY-000235'
        || ($obligation['booking_reference'] ?? '') !== 'BK-001182'
        || (int) ($payment['supplier_id'] ?? 0) !== (int) ($obligation['supplier_id'] ?? -1)
        || (int) ($payment['branch_id'] ?? 0) === (int) ($obligation['branch_id'] ?? 0)
        || ($payment['payment_scope'] ?? '') !== 'global'
        || strtoupper((string) ($payment['booking_reference'] ?? '')) !== 'GLOBAL'
        || ($payment['currency'] ?? '') !== 'PKR'
        || ($obligation['currency'] ?? '') !== 'PKR'
        || ($payment['status'] ?? '') === 'void') {
        throw new RuntimeException('Production payment, payable, or actor differs from the reviewed AirBlue case. Nothing changed.');
    }

    $allocationQuery = $db->prepare('SELECT id, allocated_amount FROM supplier_payment_allocations
        WHERE supplier_payment_id = 247 AND supplier_obligation_id = 1170 ORDER BY id DESC LIMIT 1');
    $allocationQuery->execute();
    $existing = $allocationQuery->fetch(PDO::FETCH_ASSOC);
    if ($existing !== false) {
        if ((float) $existing['allocated_amount'] !== 75342.0
            || (float) ($obligation['net_payable_amount'] ?? -1) !== 0.0) {
            throw new RuntimeException('An allocation already exists but does not match the expected settled state. Nothing changed.');
        }
        echo 'Already reconciled: allocation #', $existing['id'], ', BK-001182 payable PKR 0. Nothing changed.', PHP_EOL;
        exit(0);
    }

    if ((float) ($payment['unallocated_amount'] ?? -1) !== 78332.0
        || (float) ($obligation['net_payable_amount'] ?? -1) !== 75342.0) {
        throw new RuntimeException('The expected PKR 78,332 credit or PKR 75,342 payable has changed. Nothing changed.');
    }
    $openQuery = $db->prepare('SELECT id FROM supplier_obligations
        WHERE supplier_id = ? AND currency = "PKR" AND status <> "cancelled" AND net_payable_amount > 0.005');
    $openQuery->execute([(int) $payment['supplier_id']]);
    if (array_map('intval', $openQuery->fetchAll(PDO::FETCH_COLUMN)) !== [1170]) {
        throw new RuntimeException('Other AirBlue PKR payables are open. Nothing changed; review them before applying credit.');
    }

    $before = $balanceService->pooledPositions((int) $payment['supplier_id'], $branches);
    $db->beginTransaction();
    try {
        $result = (new SupplierSettlementWorkspaceService($app))->applyAvailableSupplierAdvancesToOpenPayables(
            $branches, $actorId, (int) $payment['supplier_id'], 'PKR', date('Y-m-d')
        );
        $afterPayment = $repo->findSupplierPaymentById(247);
        $afterObligation = $repo->findObligationById(1170);
        $allocationQuery->execute();
        $allocation = $allocationQuery->fetch(PDO::FETCH_ASSOC);
        $after = $balanceService->pooledPositions((int) $payment['supplier_id'], $branches);
        if ((float) ($result['applied_amount'] ?? 0) !== 75342.0
            || (int) ($result['application_count'] ?? 0) !== 1
            || !is_array($allocation)
            || (float) ($allocation['allocated_amount'] ?? 0) !== 75342.0
            || (float) ($afterPayment['unallocated_amount'] ?? -1) !== 2990.0
            || (float) ($afterObligation['net_payable_amount'] ?? -1) !== 0.0
            || $before !== $after) {
            throw new RuntimeException('Post-check failed; the entire reconciliation was rolled back.');
        }
        foreach (CrossBranchSupplierAllocationAudit::failures($db, 100) as $failure) {
            if ((int) ($failure['allocation_id'] ?? 0) === (int) $allocation['id']) {
                throw new RuntimeException('Cross-branch audit failed; the entire reconciliation was rolled back: ' . $failure['failure_reason']);
            }
        }
        $db->commit();
        echo 'Applied PKR 75,342 from SPAY-000235 to BK-001182.', PHP_EOL;
        echo 'Remaining payment credit: PKR 2,990. Booking payable: PKR 0.', PHP_EOL;
        echo 'Supplier account balance unchanged: PKR ', number_format((float) ($after['PKR'] ?? 0), 0), '.', PHP_EOL;
        echo 'Allocation ID: ', $allocation['id'], '.', PHP_EOL;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reconciliation stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
