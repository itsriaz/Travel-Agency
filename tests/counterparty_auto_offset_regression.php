<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

echo 'Automatic linked-party settlement regression' . PHP_EOL;
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin") ORDER BY (r.code = "super_admin") DESC, u.id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('An active branch and financial administrator are required.');
}

$beforeTreasury = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')
        ->execute(['code' => 'QA-AUTO-' . $suffix, 'name' => 'QA Auto Account ' . $suffix]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active) VALUES (:branch, :code, :name, "normal_payable", "PKR", 1)')
        ->execute(['branch' => $branchId, 'code' => 'QA-AUTO-S-' . $suffix, 'name' => 'QA Auto Supplier ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id) VALUES (:business, :supplier, :user)')
        ->execute(['business' => $businessSourceId, 'supplier' => $supplierId, 'user' => $actorId]);

    $saleBooking = 'QA-AUTO-SALE-' . $suffix;
    $purchaseBooking = 'QA-AUTO-BUY-' . $suffix;
    foreach ([[$saleBooking, $businessSourceId], [$purchaseBooking, $businessSourceId]] as [$reference, $accountId]) {
        $db->prepare('INSERT INTO bookings (booking_reference, branch_id, business_source_id, booking_status, booking_date, created_by_user_id, updated_by_user_id) VALUES (:reference, :branch, :business, "open", CURDATE(), :created_user, :updated_user)')
            ->execute(['reference' => $reference, 'branch' => $branchId, 'business' => $accountId, 'created_user' => $actorId, 'updated_user' => $actorId]);
    }
    $db->prepare('INSERT INTO customer_receivable_items (branch_id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status, created_by_user_id) VALUES (:branch, :reference, "SV-001", "PKR", 45000, 15000, 30000, "partially_paid", :user)')
        ->execute(['branch' => $branchId, 'reference' => $saleBooking, 'user' => $actorId]);
    $receivableId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id) VALUES (:supplier, :branch, :reference, "SV-001", "PKR", 100000, 0, 100000, "open", :user)')
        ->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $purchaseBooking, 'user' => $actorId]);
    $obligationId = (int) $db->lastInsertId();

    $service = new \App\Services\CounterpartyOffsetService($app);
    $settlements = $service->autoSettleForFinancialContext(
        $businessSourceId,
        0,
        $branchId,
        ['PKR'],
        date('Y-m-d'),
        $actorId
    );
    $receivable = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetchColumn();
    $payable = $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn();

    $check('Matching balances are not adjusted automatically', $settlements === []);
    $check('Customer receipt does not reduce the independent account-holder sale balance', abs((float) $receivable - 30000) <= 0.005);
    $check('Supplier payable remains PKR 100,000', abs((float) $payable - 100000) <= 0.005);
    $linkId = (int) $db->query('SELECT id FROM business_source_supplier_links WHERE business_source_id = ' . $businessSourceId)->fetchColumn();
    $check('Link reconciliation cannot auto-settle', $service->autoSettleLink($linkId, date('Y-m-d'), $actorId) === []);
    $check('Existing-balance reconciliation cannot auto-settle', $service->autoSettleExisting([$branchId], date('Y-m-d'), $actorId) === []);
    $linked = (new \App\Services\CounterpartyLinkService($app))->linkAccountToSupplier($businessSourceId, $supplierId, $actorId);
    $check('Saving the same link cannot auto-settle', $linked['automatic_settlements'] === []);
    $linkService = new \App\Services\CounterpartyLinkService($app);
    $linkService->unlinkAccountFromSupplier($linkId, $actorId, 'QA manual-only policy');
    $newLink = $linkService->linkAccountToSupplier($businessSourceId, $supplierId, $actorId);
    $check('Creating a link with matching balances cannot auto-settle', $newLink['action'] === 'linked' && $newLink['automatic_settlements'] === []);
    $check('No cash or bank movement is created', (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $beforeTreasury);

    $restoredReceivable = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetchColumn();
    $restoredPayable = $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn();
    $check('All automatic entry points leave supplier balance and customer due unchanged', abs((float) $restoredReceivable - 30000) <= 0.005 && abs((float) $restoredPayable - 100000) <= 0.005);
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

if ($failures !== []) {
    fwrite(STDERR, 'Automatic linked-party settlement regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Automatic linked-party settlement regression passed.' . PHP_EOL;
