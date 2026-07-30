<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$apply = in_array('--apply', $argv, true);

echo 'BK-000532 / BK-000533 account-supplier settlement repair' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

$sale = $db->query(
    'SELECT cri.*, b.id AS booking_id, b.business_source_id, b.branch_id AS booking_branch_id,
            bs.name AS account_name
     FROM customer_receivable_items cri
     INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
     INNER JOIN business_sources bs ON bs.id = b.business_source_id
     WHERE cri.booking_reference = "BK-000532" AND cri.due_group = "service_sale"
     ORDER BY cri.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$purchase = $db->query(
    'SELECT so.*, s.name AS supplier_name
     FROM supplier_obligations so
     INNER JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.booking_reference = "BK-000533" AND so.obligation_group = "service_cost"
     ORDER BY so.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$bk533Customer = $db->query(
    'SELECT cri.* FROM customer_receivable_items cri
     WHERE cri.booking_reference = "BK-000533" AND cri.due_group = "service_sale"
     ORDER BY cri.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (! is_array($sale) || ! is_array($purchase) || ! is_array($bk533Customer)) {
    throw new RuntimeException('The expected BK-000532 sale, BK-000533 supplier payable, or BK-000533 customer invoice was not found. No data changed.');
}
if ((int) $sale['branch_id'] !== (int) $purchase['branch_id'] || strtoupper((string) $sale['currency']) !== strtoupper((string) $purchase['currency'])) {
    throw new RuntimeException('The two positions are not in the same branch and currency. No data changed.');
}

$legacyStatement = $db->prepare(
    'SELECT DISTINCT o.*
     FROM counterparty_offsets o
     INNER JOIN counterparty_offset_receivable_allocations ra ON ra.counterparty_offset_id = o.id
     INNER JOIN counterparty_offset_payable_allocations pa ON pa.counterparty_offset_id = o.id
     WHERE o.status = "posted"
       AND ra.customer_receivable_item_id = :receivable_id
       AND pa.supplier_obligation_id = :obligation_id
     ORDER BY o.id DESC LIMIT 1'
);
$legacyStatement->execute(['receivable_id' => (int) $sale['id'], 'obligation_id' => (int) $purchase['id']]);
$legacy = $legacyStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($legacy)) {
    throw new RuntimeException('The incorrect posted BK-000532/BK-000533 legacy settlement was not found. No data changed.');
}

$linkStatement = $db->prepare(
    'SELECT * FROM business_source_supplier_links
     WHERE business_source_id = :business_source_id AND supplier_id = :supplier_id LIMIT 1'
);
$linkStatement->execute(['business_source_id' => (int) $sale['business_source_id'], 'supplier_id' => (int) $purchase['supplier_id']]);
$link = $linkStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($link)) {
    throw new RuntimeException('Account ' . (string) $sale['account_name'] . ' is not linked to supplier ' . (string) $purchase['supplier_name'] . '. No data changed.');
}

$actorId = (int) $db->query(
    'SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1'
)->fetchColumn();
if ($actorId <= 0) throw new RuntimeException('An active super administrator is required.');

$customerAllocationCount = (int) $db->query(
    'SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receivable_item_id = ' . (int) $sale['id']
)->fetchColumn();
$bk533AllocationCount = (int) $db->query(
    'SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receivable_item_id = ' . (int) $bk533Customer['id']
)->fetchColumn();
if ($customerAllocationCount !== 0 || $bk533AllocationCount !== 0) {
    throw new RuntimeException('A real customer receipt allocation now exists on BK-000532 or BK-000533. Stop and review manually; no data changed.');
}

echo '[FOUND] Legacy settlement: ' . $legacy['offset_no'] . ' / ' . $legacy['currency'] . ' ' . number_format((float) $legacy['amount'], 2) . PHP_EOL;
echo '[CURRENT] BK-000532 customer due/outstanding: ' . $sale['currency'] . ' ' . number_format((float) $sale['due_amount'], 2) . ' / ' . number_format((float) $sale['outstanding_amount'], 2) . PHP_EOL;
echo '[CURRENT] BK-000533 customer due/outstanding: ' . $bk533Customer['currency'] . ' ' . number_format((float) $bk533Customer['due_amount'], 2) . ' / ' . number_format((float) $bk533Customer['outstanding_amount'], 2) . PHP_EOL;
echo '[CURRENT] BK-000533 supplier payable: ' . $purchase['currency'] . ' ' . number_format((float) $purchase['net_payable_amount'], 2) . PHP_EOL;
echo '[PLAN] Restore BK-000532 customer outstanding to PKR 12,000, restore BK-000533 supplier payable to PKR 15,000, then post a PKR 12,000 account-holder/supplier settlement.' . PHP_EOL;
echo '[EXPECTED] PADMAARO remains due PKR 12,000; MARITES COMPR remains due PKR 18,000; Supplier Riaz remains payable PKR 3,000.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. No data changed. Re-run with --apply after confirming a database backup.' . PHP_EOL;
    exit(0);
}

$before = [
    'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
    'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
    'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
];

$db->beginTransaction();
try {
    $service = new \App\Services\CounterpartyOffsetService($app);
    $service->void((int) $legacy['id'], 'Correct legacy customer-receivable allocation to account-holder settlement', $actorId, [(int) $sale['branch_id']]);
    $new = $service->settle([
        'link_id' => (int) $link['id'],
        'branch_id' => (int) $sale['branch_id'],
        'currency' => (string) $sale['currency'],
        'offset_date' => date('Y-m-d'),
        'amount' => 12000,
        'reference_no' => 'REPAIR-BK532-BK533',
        'remarks' => 'Account Riaz recovery responsibility applied against Supplier Riaz; customer invoices remain independently payable.',
    ], $actorId, [(int) $sale['branch_id']]);

    $accountAllocation = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0) FROM counterparty_offset_account_allocations
         WHERE counterparty_offset_id = :offset_id AND customer_receivable_item_id = :receivable_id'
    );
    $accountAllocation->execute(['offset_id' => (int) $new['id'], 'receivable_id' => (int) $sale['id']]);
    if (abs((float) $accountAllocation->fetchColumn() - 12000) > 0.005) {
        throw new RuntimeException('The new settlement did not select BK-000532 exactly. The entire repair has been rolled back.');
    }
    $payableAllocation = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0) FROM counterparty_offset_payable_allocations
         WHERE counterparty_offset_id = :offset_id AND supplier_obligation_id = :obligation_id'
    );
    $payableAllocation->execute(['offset_id' => (int) $new['id'], 'obligation_id' => (int) $purchase['id']]);
    if (abs((float) $payableAllocation->fetchColumn() - 12000) > 0.005) {
        throw new RuntimeException('The new settlement did not select BK-000533 exactly. The entire repair has been rolled back.');
    }

    $truth = $db->query(
        'SELECT
            (SELECT allocated_amount FROM customer_receivable_items WHERE id = ' . (int) $sale['id'] . ') AS bk532_allocated,
            (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . (int) $sale['id'] . ') AS bk532_outstanding,
            (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . (int) $bk533Customer['id'] . ') AS bk533_customer_outstanding,
            (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . (int) $purchase['id'] . ') AS bk533_supplier_payable'
    )->fetch(PDO::FETCH_ASSOC);
    if (abs((float) $truth['bk532_allocated']) > 0.005
        || abs((float) $truth['bk532_outstanding'] - 12000) > 0.005
        || abs((float) $truth['bk533_customer_outstanding'] - 18000) > 0.005
        || abs((float) $truth['bk533_supplier_payable'] - 3000) > 0.005
    ) {
        throw new RuntimeException('Post-repair financial truth did not match the approved amounts. The entire repair has been rolled back.');
    }
    $after = [
        'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
    ];
    if ($before !== $after) {
        throw new RuntimeException('A receipt, supplier payment, or treasury movement changed unexpectedly. The entire repair has been rolled back.');
    }

    $db->commit();
    echo '[PASS] BK-000532 customer remains outstanding PKR 12,000 with no customer receipt.' . PHP_EOL;
    echo '[PASS] BK-000533 customer remains outstanding PKR 18,000 with no customer receipt.' . PHP_EOL;
    echo '[PASS] BK-000533 Supplier Riaz payable is PKR 3,000 after the PKR 12,000 account settlement.' . PHP_EOL;
    echo '[PASS] No cash, bank, customer receipt, or supplier payment movement was created.' . PHP_EOL;
    echo 'Repair complete: ' . $new['offset_no'] . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
