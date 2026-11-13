<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/SupplierObligationCoverageAudit.php';
require_once BASE_PATH . '/tests/support/SupplierPositionReconciliationAudit.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$supplierIds = [371, 418, 419, 422];
$obligationIds = [136, 183, 250, 441, 464, 474, 492, 558];
$placeholders = implode(',', array_fill(0, count($obligationIds), '?'));
$read = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$positions = [];
foreach ($supplierIds as $supplierId) {
    $result = SupplierPositionReconciliationAudit::inspect($db, $supplierId, $app);
    $positions[$supplierId] = array_map(static fn (array $group): array => [
        'currency' => $group['currency'],
        'expected_net_position' => $group['expected_net_position'],
        'actual_net_position' => $group['actual_net_position'],
        'ledger_net_position' => $group['ledger_net_position'] ?? null,
        'difference' => $group['difference'],
    ], $result['groups']);
}
echo json_encode([
    'positions' => $positions,
    'obligations' => $read("SELECT id, supplier_id, branch_id, booking_reference, currency, gross_amount,
        advance_applied_amount, net_payable_amount, status FROM supplier_obligations
        WHERE id IN ($placeholders) ORDER BY id", $obligationIds),
    'payment_allocations' => $read("SELECT a.id, a.supplier_obligation_id, a.supplier_payment_id,
        p.payment_no, p.paid_amount, p.allocated_amount AS payment_allocated_amount,
        p.unallocated_amount, p.status, a.allocated_amount FROM supplier_payment_allocations a
        JOIN supplier_payments p ON p.id = a.supplier_payment_id
        WHERE a.supplier_obligation_id IN ($placeholders) ORDER BY a.id", $obligationIds),
    'advance_applications' => $read("SELECT a.id, a.supplier_obligation_id, a.supplier_advance_id,
        a.applied_amount, a.advance_amount_consumed, a.obligation_amount_applied,
        s.deposit_amount, s.available_amount FROM supplier_advance_applications a
        JOIN supplier_advances s ON s.id = a.supplier_advance_id
        WHERE a.supplier_obligation_id IN ($placeholders) ORDER BY a.id", $obligationIds),
    'coverage_failures' => SupplierObligationCoverageAudit::failures($db, 100),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
