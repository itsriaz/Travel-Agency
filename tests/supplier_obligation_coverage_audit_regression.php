<?php

declare(strict_types=1);

require_once __DIR__ . '/support/SupplierObligationCoverageAudit.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE supplier_obligations (id INTEGER, supplier_id INTEGER, branch_id INTEGER, booking_reference TEXT, currency TEXT, gross_amount NUMERIC, advance_applied_amount NUMERIC, net_payable_amount NUMERIC)');
$db->exec('CREATE TABLE supplier_payments (id INTEGER, status TEXT)');
$db->exec('CREATE TABLE supplier_advance_applications (id INTEGER, supplier_obligation_id INTEGER)');
$db->exec('CREATE TABLE supplier_payment_allocations (id INTEGER, supplier_payment_id INTEGER, supplier_obligation_id INTEGER, allocated_amount NUMERIC)');
$db->exec('CREATE TABLE counterparty_offsets (id INTEGER, status TEXT)');
$db->exec('CREATE TABLE counterparty_offset_payable_allocations (id INTEGER, counterparty_offset_id INTEGER, supplier_obligation_id INTEGER, allocated_amount NUMERIC)');
$db->exec("INSERT INTO supplier_obligations VALUES
    (1, 10, 1, 'BK-VALID', 'PKR', 1000, 200, 300),
    (2, 10, 2, 'BK-OVERLAP', 'PKR', 1000, 1000, 0),
    (3, 20, 1, 'BK-VOID', 'AED', 500, 0, 500),
    (4, 20, 1, 'BK-OFFSET', 'AED', 500, 0, 300)");
$db->exec("INSERT INTO supplier_payments VALUES (1, 'fully_allocated'), (2, 'fully_allocated'), (3, 'void')");
$db->exec('INSERT INTO supplier_payment_allocations VALUES (11, 1, 1, 500), (12, 2, 2, 1000), (13, 3, 3, 500)');
$db->exec('INSERT INTO supplier_advance_applications VALUES (31, 1), (32, 2)');
$db->exec("INSERT INTO counterparty_offsets VALUES (1, 'posted'), (2, 'void')");
$db->exec('INSERT INTO counterparty_offset_payable_allocations VALUES (21, 1, 4, 200), (22, 2, 4, 100)');

$failures = SupplierObligationCoverageAudit::failures($db);
if (count($failures) !== 1
    || (int) $failures[0]['obligation_id'] !== 2
    || (float) $failures[0]['expected_open_amount'] !== -1000.0
    || (string) $failures[0]['advance_application_ids'] !== '32'
    || (string) $failures[0]['payment_allocation_ids'] !== '12') {
    throw new RuntimeException('Overlapping payment and advance was not isolated from valid and voided cases.');
}

echo '[PASS] Valid mixed settlements, cross-branch rows, and voided payment/offset rows are ignored.' . PHP_EOL;
echo '[PASS] A payment plus advance covering the same payable is identified with its allocation ID.' . PHP_EOL;
