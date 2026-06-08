<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);

$options = getopt('', [
    'confirm-dummy-reset',
    'include-exchange-rates',
    'apply',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/reset_dummy_business_data.php --confirm-dummy-reset [--apply] [--include-exchange-rates]' . PHP_EOL;
    echo 'Without --apply, this command prints row counts only.' . PHP_EOL;
    echo 'This reset removes dummy bookings, customers, suppliers, payments, treasury activity, documents, reminders, expenses, and audit rows.' . PHP_EOL;
    echo 'It keeps users, roles, branches, core settings, and accounting/master setup.' . PHP_EOL;
    echo 'Browser offline snapshots are stored on each device and must be refreshed or cleared in that browser.' . PHP_EOL;
    exit(0);
}

if (app_is_production()) {
    fwrite(STDERR, 'Refusing to reset dummy data while APP_ENV=production.' . PHP_EOL);
    exit(1);
}

if (! isset($options['confirm-dummy-reset'])) {
    fwrite(STDOUT, 'Reset not executed.' . PHP_EOL);
    fwrite(STDOUT, 'Run with --confirm-dummy-reset to confirm this is not production.' . PHP_EOL);
    exit(0);
}

/** @var PDO $db */
$db = $app->get('db');
$apply = isset($options['apply']);

$deleteTables = [
    'offline_draft_syncs',
    'booking_reminders',
    'booking_documents',
    'booking_service_refund_details',
    'expense_attachments',
    'business_expenses',
    'customer_receipt_allocations',
    'customer_receipts',
    'customer_receivable_items',
    'supplier_payment_allocations',
    'supplier_payments',
    'supplier_advance_applications',
    'supplier_advances',
    'supplier_obligations',
    'treasury_transactions',
    'treasury_accounts',
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
    'suppliers',
    'audit_logs',
];

if (isset($options['include-exchange-rates'])) {
    $deleteTables[] = 'exchange_rates';
}

$sequenceKeys = [
    'booking.reference.sequence',
    'customer.receipt.sequence',
    'supplier.code.sequence',
    'supplier.payment.sequence',
];

$tableExists = static function (PDO $db, string $table): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table
         LIMIT 1'
    );
    $statement->execute(['table' => $table]);

    return $statement->fetchColumn() !== false;
};

$countRows = static function (PDO $db, string $table) use ($tableExists): int {
    if (! $tableExists($db, $table)) {
        return 0;
    }

    return (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
};

echo ($apply ? 'Applying' : 'Dry run') . ' dummy business data reset.' . PHP_EOL;
echo 'Environment: ' . app_environment() . PHP_EOL;
echo 'Users, employees, roles, branches, master data, posting rules, and accounting setup will be kept.' . PHP_EOL;
echo 'Bookings, customers, suppliers, receipts/payments, payables/receivables, treasury test accounts, documents, reminders, expenses, and audit logs will be cleared.' . PHP_EOL . PHP_EOL;
echo 'Note: this server reset cannot clear browser offline snapshots cached on user devices.' . PHP_EOL;
echo 'Refresh the offline snapshot after reset so old cached names disappear from offline search.' . PHP_EOL . PHP_EOL;

foreach ($deleteTables as $table) {
    echo str_pad($table, 34) . $countRows($db, $table) . PHP_EOL;
}

if (! $apply) {
    echo PHP_EOL . 'No changes applied. Re-run with --apply after taking a backup.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    foreach ($deleteTables as $table) {
        if (! $tableExists($db, $table)) {
            continue;
        }

        $db->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
    }

    $deleteSequence = $db->prepare('DELETE FROM app_settings WHERE setting_key = :setting_key');
    foreach ($sequenceKeys as $sequenceKey) {
        $deleteSequence->execute(['setting_key' => $sequenceKey]);
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Reset failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Dummy business data reset completed.' . PHP_EOL;
echo 'Employee/admin users and system setup remain intact.' . PHP_EOL;
