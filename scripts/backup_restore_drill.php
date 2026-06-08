<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';

$loadEnvFile = static function (string $path): void {
    if (! is_file($path) || ! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (! is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separatorPosition = strpos($line, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPosition));
        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }

        if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
            continue;
        }

        $value = trim(substr($line, $separatorPosition + 1));
        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
};

$loadEnvFile(BASE_PATH . '/.env');

$options = getopt('', [
    'label::',
    'keep-restore-db',
    'mysql::',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/backup_restore_drill.php [--label=drill] [--keep-restore-db] [--mysql=C:\xampp\mysql\bin\mysql.exe]' . PHP_EOL;
    exit(0);
}

$config = require BASE_PATH . '/config/database.php';

$failures = [];
$notes = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$resolveMysqlClient = static function (?string $configuredPath): ?string {
    $candidates = [];

    if ($configuredPath !== null && trim($configuredPath) !== '') {
        $candidates[] = trim($configuredPath);
    }

    $envPath = getenv('MYSQL_CLIENT_PATH');
    if (is_string($envPath) && trim($envPath) !== '') {
        $candidates[] = trim($envPath);
    }

    $candidates[] = 'C:/xampp/mysql/bin/mysql.exe';
    $candidates[] = 'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe';
    $candidates[] = '/usr/bin/mysql';
    $candidates[] = '/usr/local/bin/mysql';
    $candidates[] = 'mysql';

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysql') {
            return $candidate;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
};

$label = trim((string) ($options['label'] ?? 'restore-drill'));
$mysqlClient = $resolveMysqlClient(isset($options['mysql']) ? (string) $options['mysql'] : null);

if ($mysqlClient === null) {
    fwrite(STDERR, 'mysql client was not found. Provide --mysql=PATH or set MYSQL_CLIENT_PATH.' . PHP_EOL);
    exit(1);
}

$sourceDsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    (string) ($config['host'] ?? '127.0.0.1'),
    (string) ($config['port'] ?? '3306'),
    (string) ($config['database'] ?? ''),
    (string) ($config['charset'] ?? 'utf8mb4')
);
$adminDsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    (string) ($config['host'] ?? '127.0.0.1'),
    (string) ($config['port'] ?? '3306'),
    (string) ($config['charset'] ?? 'utf8mb4')
);

$sourceDb = new PDO(
    $sourceDsn,
    (string) ($config['username'] ?? ''),
    (string) ($config['password'] ?? ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$adminDb = new PDO(
    $adminDsn,
    (string) ($config['username'] ?? ''),
    (string) ($config['password'] ?? ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$timestamp = date('Ymd_His');
$restoreDatabase = preg_replace('/[^a-z0-9_]+/i', '_', (string) ($config['database'] ?? 'travel_agency_ops')) . '_restore_drill_' . $timestamp;
$keepRestoreDb = isset($options['keep-restore-db']);

$backupOutput = [];
$backupExit = 1;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/backup_database.php') . ' --label=' . escapeshellarg($label),
    $backupOutput,
    $backupExit
);

$check('Backup script completed', $backupExit === 0, implode(PHP_EOL, $backupOutput));
if ($backupExit !== 0) {
    exit(1);
}

$backupFile = null;
foreach ($backupOutput as $line) {
    if (str_starts_with($line, 'Database backup created: ')) {
        $backupFile = trim(substr($line, strlen('Database backup created: ')));
        break;
    }
}

$check('Backup file path resolved', is_string($backupFile) && $backupFile !== '', (string) $backupFile);
if (! is_string($backupFile) || $backupFile === '' || ! is_file($backupFile)) {
    exit(1);
}

$check('Backup file is non-empty', filesize($backupFile) > 0, (string) filesize($backupFile));

$tableCounts = [
    'bookings',
    'booking_services',
    'customer_receipts',
    'journal_entries',
    'journal_entry_lines',
    'booking_service_events',
    'treasury_accounts',
];
$sourceCounts = [];
foreach ($tableCounts as $tableName) {
    $sourceCounts[$tableName] = (int) $sourceDb->query('SELECT COUNT(*) FROM `' . $tableName . '`')->fetchColumn();
}

$adminDb->exec('CREATE DATABASE `' . str_replace('`', '``', $restoreDatabase) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$check('Temporary restore database created', true, $restoreDatabase);

$stdin = fopen($backupFile, 'rb');
if ($stdin === false) {
    $check('Backup file could be opened for restore', false, $backupFile);
    if (! $keepRestoreDb) {
        $adminDb->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $restoreDatabase) . '`');
    }
    exit(1);
}

$command = [
    $mysqlClient,
    '--default-character-set=' . (string) ($config['charset'] ?? 'utf8mb4'),
    '--host=' . (string) ($config['host'] ?? '127.0.0.1'),
    '--port=' . (string) ($config['port'] ?? '3306'),
    '--user=' . (string) ($config['username'] ?? ''),
    $restoreDatabase,
];

$descriptors = [
    0 => $stdin,
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$environment = getenv();
if (! is_array($environment)) {
    $environment = $_ENV;
}
$password = (string) ($config['password'] ?? '');
if ($password !== '') {
    $environment['MYSQL_PWD'] = $password;
}

$process = proc_open($command, $descriptors, $pipes, BASE_PATH, $environment);
if (! is_resource($process)) {
    fclose($stdin);
    if (! $keepRestoreDb) {
        $adminDb->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $restoreDatabase) . '`');
    }
    $check('Restore process started', false, 'Could not start mysql client.');
    exit(1);
}

$restoreStdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$restoreStderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
fclose($stdin);
$restoreExit = proc_close($process);

$check('Backup imported into restore database', $restoreExit === 0, trim($restoreStdout . PHP_EOL . $restoreStderr));
if ($restoreExit !== 0) {
    if (! $keepRestoreDb) {
        $adminDb->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $restoreDatabase) . '`');
    }
    exit(1);
}

$restoreDsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    (string) ($config['host'] ?? '127.0.0.1'),
    (string) ($config['port'] ?? '3306'),
    $restoreDatabase,
    (string) ($config['charset'] ?? 'utf8mb4')
);
$restoreDb = new PDO(
    $restoreDsn,
    (string) ($config['username'] ?? ''),
    (string) ($config['password'] ?? ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$restoredCounts = [];
foreach ($tableCounts as $tableName) {
    $restoredCounts[$tableName] = (int) $restoreDb->query('SELECT COUNT(*) FROM `' . $tableName . '`')->fetchColumn();
}

$check('Critical table row counts match after restore', $sourceCounts === $restoredCounts, json_encode([
    'source' => $sourceCounts,
    'restore' => $restoredCounts,
], JSON_UNESCAPED_SLASHES));

$sourceMigrations = (int) $sourceDb->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
$restoredMigrations = (int) $restoreDb->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
$check('Migration history copied into restore database', $sourceMigrations === $restoredMigrations, 'source=' . $sourceMigrations . ', restore=' . $restoredMigrations);

$restoredUsers = (int) $restoreDb->query('SELECT COUNT(*) FROM users')->fetchColumn();
$check('Restore database is queryable for authentication foundation', $restoredUsers > 0, 'users=' . $restoredUsers);

if (! $keepRestoreDb) {
    $adminDb->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $restoreDatabase) . '`');
    $check('Temporary restore database dropped after verification', true, $restoreDatabase);
} else {
    $notes[] = 'Temporary restore database kept: ' . $restoreDatabase;
}

if ($notes !== []) {
    echo PHP_EOL . 'Notes:' . PHP_EOL;
    foreach ($notes as $note) {
        echo ' - ' . $note . PHP_EOL;
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Backup/restore drill failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Backup/restore drill passed.' . PHP_EOL;
