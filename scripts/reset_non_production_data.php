<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);

$options = getopt('', [
    'confirm-non-production-reset',
    'include-suppliers',
    'include-exchange-rates',
    'apply',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/reset_non_production_data.php --confirm-non-production-reset [--apply] [--include-suppliers] [--include-exchange-rates]' . PHP_EOL;
    echo 'Without --apply, this command prints row counts only.' . PHP_EOL;
    exit(0);
}

if (app_is_production()) {
    fwrite(STDERR, 'Refusing to reset data while APP_ENV=production.' . PHP_EOL);
    exit(1);
}

if (! isset($options['confirm-non-production-reset'])) {
    fwrite(STDOUT, 'Reset not executed.' . PHP_EOL);
    fwrite(STDOUT, 'Run with --confirm-non-production-reset to confirm this is not production.' . PHP_EOL);
    exit(0);
}

/** @var PDO $db */
$db = $app->get('db');
$apply = isset($options['apply']);

$deleteTables = [
    'offline_draft_syncs',
    'booking_reminders',
    'booking_documents',
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
    'audit_logs',
];

if (isset($options['include-suppliers'])) {
    $deleteTables[] = 'suppliers';
}

if (isset($options['include-exchange-rates'])) {
    $deleteTables[] = 'exchange_rates';
}

$sequenceKeys = [
    'booking.reference.sequence',
    'customer.receipt.sequence',
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

echo ($apply ? 'Applying' : 'Dry run') . ' non-production data reset.' . PHP_EOL;
echo 'Environment: ' . app_environment() . PHP_EOL;

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

echo PHP_EOL . 'Non-production data reset completed.' . PHP_EOL;
echo 'Run database/seed.php if you want to restore foundation seed users and registers.' . PHP_EOL;
