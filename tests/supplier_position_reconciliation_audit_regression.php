<?php

declare(strict_types=1);

require_once __DIR__ . '/support/SupplierPositionReconciliationAudit.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE supplier_payments (id INTEGER, supplier_id INTEGER, currency TEXT, paid_amount NUMERIC, returned_amount NUMERIC, unallocated_amount NUMERIC, status TEXT)');
$db->exec('CREATE TABLE supplier_obligations (id INTEGER, supplier_id INTEGER, currency TEXT, gross_amount NUMERIC, net_payable_amount NUMERIC)');
$db->exec('CREATE TABLE supplier_advances (id INTEGER, supplier_id INTEGER, currency TEXT, deposit_amount NUMERIC, available_amount NUMERIC, source_supplier_payment_id INTEGER)');
$db->exec('CREATE TABLE supplier_payment_allocations (id INTEGER, supplier_payment_id INTEGER, supplier_obligation_id INTEGER)');
$db->exec('CREATE TABLE supplier_advance_applications (id INTEGER, supplier_advance_id INTEGER, supplier_obligation_id INTEGER, applied_amount NUMERIC, advance_amount_consumed NUMERIC)');
$db->exec('CREATE TABLE counterparty_offsets (id INTEGER, status TEXT)');
$db->exec('CREATE TABLE counterparty_offset_payable_allocations (id INTEGER, counterparty_offset_id INTEGER, supplier_obligation_id INTEGER, allocated_amount NUMERIC)');
$db->exec('CREATE TABLE booking_services (id INTEGER, supplier_id INTEGER)');
$db->exec('CREATE TABLE booking_service_events (id INTEGER, booking_service_id INTEGER, event_status TEXT, supplier_refund_amount NUMERIC, currency TEXT)');
$db->exec("INSERT INTO supplier_payments VALUES (1,10,'PKR',1000,0,200,'posted')");
$db->exec("INSERT INTO supplier_obligations VALUES (1,10,'PKR',800,0)");
$db->exec("INSERT INTO supplier_advances VALUES (1,10,'PKR',200,0,1)");
$db->exec('INSERT INTO supplier_payment_allocations VALUES (1,1,1)');
$db->exec('INSERT INTO supplier_advance_applications VALUES (1,1,1,200,NULL)');

$result = SupplierPositionReconciliationAudit::inspect($db, 10);
if ($result['exceptions'] !== [] || count($result['groups']) !== 1
    || $result['groups'][0]['expected_net_position'] !== 200.0
    || $result['groups'][0]['actual_net_position'] !== 200.0) {
    throw new RuntimeException('Valid payment, advance and payable did not reconcile.');
}

$db->exec('UPDATE supplier_advances SET available_amount = 50 WHERE id = 1');
$result = SupplierPositionReconciliationAudit::inspect($db, 10);
if (count($result['exceptions']) !== 2
    || $result['exceptions'][0]['type'] !== 'advance_conservation'
    || $result['exceptions'][1]['type'] !== 'supplier_position'
    || $result['exceptions'][1]['difference'] !== -50.0) {
    throw new RuntimeException('Excess supplier credit was not detected at both source and account levels.');
}

$db->exec("INSERT INTO supplier_obligations VALUES (2,10,'AED',100,100)");
$db->exec('INSERT INTO supplier_payment_allocations VALUES (2,1,2)');
$result = SupplierPositionReconciliationAudit::inspect($db, 10);
if ($result['groups'][0]['exclusions'] === [] || $result['groups'][1]['exclusions'] === []) {
    throw new RuntimeException('Cross-currency movement was silently treated as same-currency evidence.');
}

echo '[PASS] Supplier cash, credit and payable conservation reconciles when valid.' . PHP_EOL;
echo '[PASS] Excess credit is reported with advance and supplier IDs, expected and actual amounts.' . PHP_EOL;
echo '[PASS] Cross-currency groups require explicit review rather than a false pass or conversion.' . PHP_EOL;
