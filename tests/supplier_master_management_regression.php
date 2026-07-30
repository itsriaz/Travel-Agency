<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Services\SupplierMasterService;

$app = (isset($app) && $app instanceof App) ? $app : App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$service = new SupplierMasterService($app);

$fixture = $db->query(
    'SELECT s.*, (
        (SELECT COUNT(*) FROM booking_services bs WHERE bs.supplier_id = s.id) +
        (SELECT COUNT(*) FROM supplier_obligations so WHERE so.supplier_id = s.id) +
        (SELECT COUNT(*) FROM supplier_payments sp WHERE sp.supplier_id = s.id) +
        (SELECT COUNT(*) FROM supplier_advances sa WHERE sa.supplier_id = s.id)
     ) AS reference_count
     FROM suppliers s
     WHERE s.branch_id IS NOT NULL
     ORDER BY reference_count DESC, s.id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$actorId = (int) ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Supplier master management regression' . PHP_EOL;

if (! is_array($fixture) || (int) ($fixture['id'] ?? 0) <= 0 || $actorId <= 0) {
    echo '[FAIL] Supplier and user fixtures are available' . PHP_EOL;
    exit(1);
}

$supplierId = (int) $fixture['id'];
$branchId = (int) $fixture['branch_id'];
$originalCode = (string) $fixture['code'];
$newName = 'QA Supplier Rename ' . date('His') . '-' . $supplierId;
$referenceTables = [
    'booking_services',
    'supplier_obligations',
    'supplier_payments',
    'supplier_advances',
    'business_source_supplier_links',
    'counterparty_offsets',
];
$countsBefore = [];
foreach ($referenceTables as $table) {
    $statement = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id = :supplier_id');
    $statement->execute(['supplier_id' => $supplierId]);
    $countsBefore[$table] = (int) $statement->fetchColumn();
}

$db->beginTransaction();
try {
    $updated = $service->update($supplierId, [
        'name' => $newName,
        'branch_id' => $branchId,
        'default_currency' => (string) $fixture['default_currency'],
        'contact_person' => 'QA Contact',
        'phone' => '+92 300 0000000',
        'email' => 'supplier.qa@example.com',
        'address' => 'QA supplier address',
        'is_active' => '1',
        'notes' => 'Supplier master regression',
    ], $actorId, [$branchId]);

    $storedStatement = $db->prepare('SELECT * FROM suppliers WHERE id = :id');
    $storedStatement->execute(['id' => $supplierId]);
    $stored = $storedStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $check(
        'Supplier identity is preserved while master details change',
        (int) ($stored['id'] ?? 0) === $supplierId
            && (string) ($stored['code'] ?? '') === $originalCode
            && (string) ($stored['name'] ?? '') === $newName
            && (string) ($stored['email'] ?? '') === 'supplier.qa@example.com'
            && (int) ($updated['id'] ?? 0) === $supplierId
    );

    $countsAfter = [];
    foreach ($referenceTables as $table) {
        $statement = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id = :supplier_id');
        $statement->execute(['supplier_id' => $supplierId]);
        $countsAfter[$table] = (int) $statement->fetchColumn();
    }
    $check(
        'Invoices, obligations, payments, advances, links, and offsets keep the same supplier ID',
        $countsAfter === $countsBefore,
        json_encode($countsAfter, JSON_UNESCAPED_SLASHES)
    );

    $joinedNames = [];
    foreach (['supplier_obligations', 'supplier_payments', 'supplier_advances'] as $table) {
        if (($countsAfter[$table] ?? 0) <= 0) {
            continue;
        }
        $statement = $db->prepare(
            'SELECT s.name
             FROM ' . $table . ' financial_record
             INNER JOIN suppliers s ON s.id = financial_record.supplier_id
             WHERE financial_record.supplier_id = :supplier_id
             LIMIT 1'
        );
        $statement->execute(['supplier_id' => $supplierId]);
        $joinedNames[$table] = (string) ($statement->fetchColumn() ?: '');
    }
    $check(
        'Existing supplier financial records display the corrected master name',
        $joinedNames === [] || count(array_filter($joinedNames, static fn (string $name): bool => $name === $newName)) === count($joinedNames),
        json_encode($joinedNames, JSON_UNESCAPED_SLASHES)
    );

    $auditStatement = $db->prepare(
        'SELECT COUNT(*) FROM audit_logs
         WHERE event_name = "supplier.master.updated"
           AND JSON_EXTRACT(payload_json, "$.supplier_id") = :supplier_id'
    );
    $auditStatement->execute(['supplier_id' => $supplierId]);
    $check('Supplier master correction is audit logged', (int) $auditStatement->fetchColumn() > 0);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$check(
    'Regression fixture was rolled back',
    (string) ($db->query('SELECT name FROM suppliers WHERE id = ' . $supplierId)->fetchColumn() ?: '') === (string) $fixture['name']
);

if ($failures !== []) {
    exit(1);
}

echo 'Supplier master management regression passed.' . PHP_EOL;

