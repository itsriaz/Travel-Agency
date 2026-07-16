<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

/** @var PDO $db */
$db = app()->get('db');
$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$count = static function (string $table) use ($db): int {
    return (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
};

$expectedEmptyTables = [
    'offline_draft_syncs',
    'booking_reminders',
    'booking_documents',
    'booking_service_refund_details',
    'service_financial_corrections',
    'business_expense_corrections',
    'expense_attachments',
    'business_expenses',
    'customer_advance_corrections',
    'customer_advance_refunds',
    'customer_receipt_allocations',
    'customer_receipts',
    'customer_receivable_items',
    'supplier_payment_allocations',
    'supplier_payments',
    'supplier_advance_applications',
    'supplier_advances',
    'supplier_obligations',
    'journal_entry_lines',
    'journal_entries',
    'booking_service_events',
    'service_air_ticket',
    'service_visa',
    'service_umrah',
    'service_hotel',
    'service_transport',
    'service_tour',
    'service_other',
    'booking_services',
    'booking_travelers',
    'booking_parties',
    'bookings',
    'travelers',
];

foreach ($expectedEmptyTables as $table) {
    $rows = $count($table);
    $check('Clean table: ' . $table, $rows === 0, $rows . ' rows');
}

$check('Two active branches exist', $count('branches') >= 2);

$roleStatement = $db->query("SELECT code FROM roles WHERE code IN ('super_admin', 'branch_admin', 'employee')");
$roles = $roleStatement !== false ? $roleStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$missingRoles = array_values(array_diff(['super_admin', 'branch_admin', 'employee'], $roles));
$check('Required roles remain', $missingRoles === [], $missingRoles !== [] ? implode(', ', $missingRoles) : '');

$check('At least one active super admin remains', (int) $db->query(
    "SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code = 'super_admin'"
)->fetchColumn() >= 1);

$check('Currencies remain', $count('currencies') >= 3);
$check('Service types remain', $count('service_types') >= 1);
$check('Payment methods remain', $count('payment_methods') >= 1);
$check('Chart of accounts remains', $count('chart_of_accounts') >= 1);
$check('Posting rules remain', $count('posting_rules') >= 1);

$executed = $db->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$migrationFiles = array_map(
    static fn (string $path): string => basename($path),
    glob(BASE_PATH . '/database/migrations/*.php') ?: []
);
$pending = array_values(array_diff($migrationFiles, $executed));
$check('No pending migrations', $pending === [], $pending !== [] ? implode(', ', $pending) : '');

if ($failures !== []) {
    echo PHP_EOL . 'Clean test database verification failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Clean test database verification passed.' . PHP_EOL;
