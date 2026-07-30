<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$apply = in_array('--apply', $argv, true);

echo 'BK-000001 / BK-000002 account-supplier offset repair' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

$saleStatement = $db->prepare(
    'SELECT cri.*, b.business_source_id, b.branch_id AS booking_branch_id, bs.name AS account_name
     FROM customer_receivable_items cri
     INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
     INNER JOIN business_sources bs ON bs.id = b.business_source_id
     WHERE cri.booking_reference = "BK-000001" AND cri.due_group = "service_sale"
     ORDER BY cri.id LIMIT 1'
);
$saleStatement->execute();
$sale = $saleStatement->fetch(PDO::FETCH_ASSOC);

$purchaseStatement = $db->prepare(
    'SELECT so.*, s.name AS supplier_name
     FROM supplier_obligations so
     INNER JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.booking_reference = "BK-000002" AND so.obligation_group = "service_cost"
     ORDER BY so.id LIMIT 1'
);
$purchaseStatement->execute();
$purchase = $purchaseStatement->fetch(PDO::FETCH_ASSOC);

$bk2CustomerStatement = $db->prepare(
    'SELECT * FROM customer_receivable_items
     WHERE booking_reference = "BK-000002" AND due_group = "service_sale"
     ORDER BY id LIMIT 1'
);
$bk2CustomerStatement->execute();
$bk2Customer = $bk2CustomerStatement->fetch(PDO::FETCH_ASSOC);

if (! is_array($sale) || ! is_array($purchase) || ! is_array($bk2Customer)) {
    throw new RuntimeException('The expected BK-000001 sale or BK-000002 customer/supplier position was not found. No data changed.');
}
if ((int) $sale['branch_id'] !== (int) $purchase['branch_id'] || strtoupper((string) $sale['currency']) !== strtoupper((string) $purchase['currency'])) {
    throw new RuntimeException('The linked account sale and supplier payable are not in the same branch and currency. No data changed.');
}
if (abs((float) $sale['due_amount'] - 1200) > 0.005 || abs((float) $purchase['gross_amount'] - 3000) > 0.005) {
    throw new RuntimeException('The expected PKR 1,200 account sale or PKR 3,000 supplier payable has changed. Stop for manual review.');
}

$linkStatement = $db->prepare(
    'SELECT * FROM business_source_supplier_links
     WHERE business_source_id = :business_source_id AND supplier_id = :supplier_id LIMIT 1'
);
$linkStatement->execute([
    'business_source_id' => (int) $sale['business_source_id'],
    'supplier_id' => (int) $purchase['supplier_id'],
]);
$link = $linkStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($link)) {
    throw new RuntimeException('Account ' . $sale['account_name'] . ' is not linked to supplier ' . $purchase['supplier_name'] . '. No data changed.');
}

$offsetStatement = $db->prepare(
    'SELECT DISTINCT o.*
     FROM counterparty_offsets o
     INNER JOIN counterparty_offset_account_allocations aa ON aa.counterparty_offset_id = o.id
     INNER JOIN counterparty_offset_payable_allocations pa ON pa.counterparty_offset_id = o.id
     WHERE o.status = "posted"
       AND aa.customer_receivable_item_id = :receivable_id
       AND pa.supplier_obligation_id = :obligation_id
     ORDER BY o.id DESC LIMIT 1'
);
$offsetStatement->execute([
    'receivable_id' => (int) $sale['id'],
    'obligation_id' => (int) $purchase['id'],
]);
$incorrectOffset = $offsetStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($incorrectOffset)) {
    throw new RuntimeException('The existing BK-000001/BK-000002 linked settlement was not found. No data changed.');
}

$actorId = (int) $db->query(
    'SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1'
)->fetchColumn();
if ($actorId <= 0) throw new RuntimeException('An active super administrator is required.');

echo '[CURRENT] Customer Jalal invoice / paid / due: PKR '
    . number_format((float) $sale['due_amount'], 2) . ' / '
    . number_format((float) $sale['allocated_amount'], 2) . ' / '
    . number_format((float) $sale['outstanding_amount'], 2) . PHP_EOL;
echo '[CURRENT] Customer Ghazan outstanding: PKR ' . number_format((float) $bk2Customer['outstanding_amount'], 2) . PHP_EOL;
echo '[CURRENT] Supplier Riaz payable after incorrect offset: PKR ' . number_format((float) $purchase['net_payable_amount'], 2) . PHP_EOL;
echo '[CURRENT] Incorrect linked offset: PKR ' . number_format((float) $incorrectOffset['amount'], 2) . PHP_EOL;
echo '[PLAN] Replace it with PKR 1,200 Account Riaz to Supplier Riaz non-cash settlement.' . PHP_EOL;
echo '[EXPECTED] Jalal due PKR 900; Ghazan due PKR 3,300; Supplier Riaz payable PKR 1,800.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. No data changed. Re-run with --apply after confirmation.' . PHP_EOL;
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
    $service->void(
        (int) $incorrectOffset['id'],
        'Correct account-holder settlement basis from customer outstanding to full account sale',
        $actorId,
        [(int) $sale['branch_id']]
    );
    $new = $service->settle([
        'link_id' => (int) $link['id'],
        'branch_id' => (int) $sale['branch_id'],
        'currency' => (string) $sale['currency'],
        'offset_date' => date('Y-m-d'),
        'amount' => 1200,
        'reference_no' => 'REPAIR-BK1-BK2',
        'remarks' => 'Account Riaz sale responsibility applied against Supplier Riaz; customer payments remain independent.',
    ], $actorId, [(int) $sale['branch_id']]);

    $accountAllocation = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0)
         FROM counterparty_offset_account_allocations
         WHERE counterparty_offset_id = :offset_id AND customer_receivable_item_id = :receivable_id'
    );
    $accountAllocation->execute(['offset_id' => (int) $new['id'], 'receivable_id' => (int) $sale['id']]);
    $payableAllocation = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0)
         FROM counterparty_offset_payable_allocations
         WHERE counterparty_offset_id = :offset_id AND supplier_obligation_id = :obligation_id'
    );
    $payableAllocation->execute(['offset_id' => (int) $new['id'], 'obligation_id' => (int) $purchase['id']]);
    if (abs((float) $accountAllocation->fetchColumn() - 1200) > 0.005
        || abs((float) $payableAllocation->fetchColumn() - 1200) > 0.005
    ) {
        throw new RuntimeException('The corrected offset did not select exactly BK-000001 and BK-000002. All changes were rolled back.');
    }

    $saleNow = $db->query('SELECT allocated_amount, outstanding_amount FROM customer_receivable_items WHERE id = ' . (int) $sale['id'])->fetch(PDO::FETCH_ASSOC);
    $bk2Now = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . (int) $bk2Customer['id'])->fetch(PDO::FETCH_ASSOC);
    $purchaseNow = $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . (int) $purchase['id'])->fetch(PDO::FETCH_ASSOC);
    if (abs((float) $saleNow['allocated_amount'] - 300) > 0.005
        || abs((float) $saleNow['outstanding_amount'] - 900) > 0.005
        || abs((float) $bk2Now['outstanding_amount'] - 3300) > 0.005
        || abs((float) $purchaseNow['net_payable_amount'] - 1800) > 0.005
    ) {
        throw new RuntimeException('Corrected financial truth does not match the approved amounts. All changes were rolled back.');
    }

    $after = [
        'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
    ];
    if ($before !== $after) {
        throw new RuntimeException('A receipt, supplier payment, or treasury movement changed unexpectedly. All changes were rolled back.');
    }

    $db->commit();
    echo '[PASS] Jalal still owes PKR 900; the PKR 300 customer receipt is unchanged.' . PHP_EOL;
    echo '[PASS] Ghazan still owes PKR 3,300.' . PHP_EOL;
    echo '[PASS] Supplier Riaz payable is PKR 1,800 after the PKR 1,200 account settlement.' . PHP_EOL;
    echo '[PASS] No customer payment, supplier payment, cash, or bank movement was created.' . PHP_EOL;
    echo 'Repair complete: ' . $new['offset_no'] . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
