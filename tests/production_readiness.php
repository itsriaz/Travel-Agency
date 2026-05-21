<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$warn = static function (string $label, bool $passed, string $details = ''): void {
    echo ($passed ? '[PASS] ' : '[WARN] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
};

$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         LIMIT 1'
    );
    $statement->execute(['table_name' => $table]);

    return $statement->fetchColumn() !== false;
};

$columnExists = static function (string $table, string $column) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $statement->fetchColumn() !== false;
};

$check('PHP version is 8.2+', PHP_VERSION_ID >= 80200, PHP_VERSION);
$check('PDO MySQL extension is loaded', extension_loaded('pdo_mysql'));
$check('mbstring extension is loaded', extension_loaded('mbstring'));
$check('fileinfo extension is loaded', extension_loaded('fileinfo'));
$check('OpenSSL extension is loaded', extension_loaded('openssl'));
$check('Database backup script exists', is_file(BASE_PATH . '/scripts/backup_database.php'));
$check('Production preflight script exists', is_file(BASE_PATH . '/scripts/preflight_production.php'));
$check('Non-production reset script exists', is_file(BASE_PATH . '/scripts/reset_non_production_data.php'));
$check('Clean test database verification script exists', is_file(BASE_PATH . '/scripts/verify_clean_test_database.php'));
$check('Storage document audit script exists', is_file(BASE_PATH . '/scripts/audit_storage_documents.php'));
$check('Environment config audit script exists', is_file(BASE_PATH . '/scripts/audit_environment_config.php'));
$backupScript = is_file(BASE_PATH . '/scripts/backup_database.php')
    ? (string) file_get_contents(BASE_PATH . '/scripts/backup_database.php')
    : '';
$check('Database backup uses transaction-safe dump option', str_contains($backupScript, '--single-transaction'));
$check('Database backup writes outside public web root by default', str_contains($backupScript, 'storage/backups/database'));

$sameSiteValues = ['Lax', 'Strict', 'None'];
$sessionSameSite = ucfirst(strtolower((string) config('security.session.cookie_samesite', 'Lax')));
$trustedDeviceSameSite = ucfirst(strtolower((string) config('security.trusted_device.cookie_samesite', 'Lax')));
$check('Session SameSite value is valid', in_array($sessionSameSite, $sameSiteValues, true), $sessionSameSite);
$check('Trusted device SameSite value is valid', in_array($trustedDeviceSameSite, $sameSiteValues, true), $trustedDeviceSameSite);
$check('Security headers are enabled', (bool) config('security.headers.enabled', false));
$check('Content Security Policy is configured', trim((string) config('security.headers.content_security_policy', '')) !== '');
$check('Referrer Policy is configured', trim((string) config('security.headers.referrer_policy', '')) !== '');
$check('Permissions Policy is configured', trim((string) config('security.headers.permissions_policy', '')) !== '');
$check('Health check controller exists', is_file(BASE_PATH . '/app/Controllers/HealthController.php'));
$check('Health check service exists', is_file(BASE_PATH . '/app/Services/HealthCheckService.php'));
$userPolicy = is_file(BASE_PATH . '/app/Policies/UserPolicy.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Policies/UserPolicy.php')
    : '';
$check('User policy uses current role names', ! str_contains($userPolicy, 'branch_user'));
$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$deactivateRouteIsFinancialAdminOnly = preg_match(
    '#/workspace/services/deactivate.*FinancialAdminMiddleware::class#',
    str_replace(["\r", "\n"], ' ', $publicIndex)
) === 1;
$check('Service deactivate route requires financial admin', $deactivateRouteIsFinancialAdminOnly);
$reportServiceSource = is_file(BASE_PATH . '/app/Services/ReportService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php')
    : '';
$reportRepositorySource = is_file(BASE_PATH . '/app/Repositories/ReportRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php')
    : '';
$check(
    'Unallocated money trace report is available',
    str_contains($reportServiceSource, "'unallocated_money' => 'Unallocated Money Trace'")
        && str_contains($reportRepositorySource, 'function unallocatedMoneyTrace')
);

if (app_is_production()) {
    $appKey = trim((string) config('app.key', ''));
    $decodedAppKey = str_starts_with($appKey, 'base64:')
        ? base64_decode(substr($appKey, 7), true)
        : false;
    $defaultAppKey = 'base64:Wm5uWGQ0blFSbVQ4ME5hL2p3VVRYeG9NcnN0Qk9ud3pPaGRYRE1vV0d6TT0=';
    $placeholderAppKey = 'base64:REPLACE_WITH_A_REAL_32_BYTE_BASE64_KEY';
    $check('Production APP_KEY is a base64 32-byte key', is_string($decodedAppKey) && strlen($decodedAppKey) === 32);
    $check('Production APP_KEY is not the bundled/default key', $appKey !== $defaultAppKey && $appKey !== $placeholderAppKey);
    $check('Production APP_URL uses HTTPS', str_starts_with(strtolower((string) config('app.url', '')), 'https://'), (string) config('app.url', ''));
    $check('Production session cookie is secure', (bool) config('security.session.cookie_secure', false));
    $check('Production trusted-device cookie is secure', (bool) config('security.trusted_device.cookie_secure', false));
    $check('Production HSTS is enabled', (bool) config('security.headers.hsts_enabled', false));
    $check('Production health check token is configured', trim((string) config('security.health.token', '')) !== '');
}

$documentExtensions = array_map('strtolower', (array) config('security.documents.allowed_extensions', []));
$blockedDocumentExtensions = array_intersect($documentExtensions, ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'js', 'html', 'htm', 'svg']);
$check('Document upload extensions are non-executable', $blockedDocumentExtensions === [], $blockedDocumentExtensions !== [] ? implode(', ', $blockedDocumentExtensions) : '');

$documentStoragePath = realpath(BASE_PATH . '/storage/documents') ?: BASE_PATH . '/storage/documents';
$publicPath = realpath(BASE_PATH . '/public') ?: BASE_PATH . '/public';
$normalizedDocumentStoragePath = str_replace('\\', '/', $documentStoragePath);
$normalizedPublicPath = rtrim(str_replace('\\', '/', $publicPath), '/') . '/';
$check('Document storage is outside public web root', ! str_starts_with($normalizedDocumentStoragePath . '/', $normalizedPublicPath));

$roleStatement = $db->prepare("SELECT code FROM roles WHERE code IN ('super_admin', 'branch_admin', 'employee')");
$roleStatement->execute();
$requiredRoles = ['super_admin', 'branch_admin', 'employee'];
$foundRoles = $roleStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];
$missingRoles = array_values(array_diff($requiredRoles, $foundRoles));
$check('Required roles exist', $missingRoles === [], $missingRoles !== [] ? implode(', ', $missingRoles) : '');

$check('Migrations table exists', $tableExists('migrations'));
if ($tableExists('migrations')) {
    $executed = $db->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $migrationFiles = array_map(
        static fn (string $path): string => basename($path),
        glob(BASE_PATH . '/database/migrations/*.php') ?: []
    );
    $pending = array_values(array_diff($migrationFiles, $executed));
    $check('No pending migrations', $pending === [], $pending !== [] ? implode(', ', $pending) : '');
}

foreach ([
    'branches',
    'users',
    'bookings',
    'booking_services',
    'service_air_ticket',
    'service_visa',
    'service_umrah',
    'service_hotel',
    'service_transport',
    'service_tour',
    'service_other',
    'booking_service_events',
    'customer_receipts',
    'customer_receivable_items',
    'supplier_payments',
    'supplier_payment_allocations',
    'supplier_obligations',
    'journal_entries',
    'journal_entry_lines',
    'offline_draft_syncs',
] as $table) {
    $check('Required table exists: ' . $table, $tableExists($table));
}

$check(
    'Supplier payment journal linkage column exists',
    $columnExists('journal_entry_lines', 'supplier_payment_id')
);

foreach ([
    'event_type',
    'event_status',
    'customer_refund_amount',
    'supplier_refund_amount',
    'journal_entry_id',
] as $column) {
    $check('Booking service event column exists: ' . $column, $columnExists('booking_service_events', $column));
}

foreach ([
    'service_visa' => ['visa_country', 'visa_type', 'application_reference', 'passport_number', 'submission_date', 'issue_date', 'expiry_date', 'visa_status'],
    'service_umrah' => ['package_name', 'mofa_reference', 'departure_date', 'return_date', 'hotel_name', 'transport_notes'],
    'service_hotel' => ['hotel_name', 'city', 'confirmation_number', 'check_in_date', 'check_out_date', 'room_type', 'guest_count'],
    'service_transport' => ['transport_mode', 'vehicle_type', 'pickup_date', 'pickup_location', 'dropoff_location', 'driver_detail', 'route_notes'],
    'service_tour' => ['tour_name', 'destination', 'confirmation_number', 'start_date', 'end_date', 'inclusions'],
    'service_other' => ['label', 'reference_number', 'service_date', 'provider_name'],
] as $table => $columns) {
    foreach ($columns as $column) {
        $check('Non-air service column exists: ' . $table . '.' . $column, $columnExists($table, $column));
    }
}

$requiredAccounts = [
    'AR_CONTROL',
    'AP_CONTROL',
    'CUSTOMER_CREDIT',
    'SUPPLIER_ADVANCES',
    'CASH_ON_HAND',
    'BANK_CLEARING',
    'CARD_CLEARING',
    'SERVICE_REVENUE',
    'SERVICE_COST',
    'CARD_CHARGES',
];
$accountStatement = $db->prepare('SELECT code FROM chart_of_accounts WHERE code IN (' . implode(',', array_fill(0, count($requiredAccounts), '?')) . ')');
$accountStatement->execute($requiredAccounts);
$foundAccounts = $accountStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];
$missingAccounts = array_values(array_diff($requiredAccounts, $foundAccounts));
$check('Required system accounts exist', $missingAccounts === [], $missingAccounts !== [] ? implode(', ', $missingAccounts) : '');

$imbalanceStatement = $db->query(
    'SELECT je.id,
            ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit_total,
            ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit_total
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     GROUP BY je.id
     HAVING debit_total <> credit_total
     LIMIT 10'
);
$imbalances = $imbalanceStatement->fetchAll() ?: [];
$check('All journal entries are balanced', $imbalances === [], $imbalances !== [] ? json_encode($imbalances, JSON_UNESCAPED_SLASHES) : '');

$negativeReceivables = $db->query(
    'SELECT id, booking_reference, service_line_reference, currency, outstanding_amount
     FROM customer_receivable_items
     WHERE outstanding_amount < -0.005
     LIMIT 10'
)->fetchAll() ?: [];
$check('No negative customer receivable outstanding amounts', $negativeReceivables === [], $negativeReceivables !== [] ? json_encode($negativeReceivables, JSON_UNESCAPED_SLASHES) : '');

$negativePayables = $db->query(
    'SELECT id, booking_reference, service_line_reference, currency, net_payable_amount
     FROM supplier_obligations
     WHERE net_payable_amount < -0.005
     LIMIT 10'
)->fetchAll() ?: [];
$check('No negative supplier payable amounts', $negativePayables === [], $negativePayables !== [] ? json_encode($negativePayables, JSON_UNESCAPED_SLASHES) : '');

$receiptMismatches = $db->query(
    'SELECT id, booking_reference, receipt_no, currency, received_amount, allocated_amount, unallocated_amount
     FROM customer_receipts
     WHERE status <> "void"
       AND ABS(received_amount - (allocated_amount + unallocated_amount)) > 0.005
     LIMIT 10'
)->fetchAll() ?: [];
$warn('Customer receipt allocation totals are consistent', $receiptMismatches === [], $receiptMismatches !== [] ? json_encode($receiptMismatches, JSON_UNESCAPED_SLASHES) : '');

$supplierPaymentMismatches = $db->query(
    'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount
     FROM supplier_payments
     WHERE status <> "void"
       AND ABS(paid_amount - (allocated_amount + unallocated_amount)) > 0.005
     LIMIT 10'
)->fetchAll() ?: [];
$warn('Supplier payment allocation totals are consistent', $supplierPaymentMismatches === [], $supplierPaymentMismatches !== [] ? json_encode($supplierPaymentMismatches, JSON_UNESCAPED_SLASHES) : '');

$supplierAdvanceMismatches = $db->query(
    'SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount
     FROM supplier_advances
     WHERE available_amount < -0.005
        OR available_amount - deposit_amount > 0.005
     LIMIT 10'
)->fetchAll() ?: [];
$warn('Supplier advance balances are within valid range', $supplierAdvanceMismatches === [], $supplierAdvanceMismatches !== [] ? json_encode($supplierAdvanceMismatches, JSON_UNESCAPED_SLASHES) : '');

if ($failures !== []) {
    echo PHP_EOL . 'Production readiness checks failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Production readiness checks passed.' . PHP_EOL;
