<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

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
    'output-dir::',
    'mysqldump::',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/backup_database.php [--label=before-release] [--output-dir=storage/backups/database] [--mysqldump=C:\xampp\mysql\bin\mysqldump.exe]' . PHP_EOL;
    exit(0);
}

$config = require BASE_PATH . '/config/database.php';

$database = trim((string) ($config['database'] ?? ''));
if ($database === '') {
    fwrite(STDERR, 'Database name is not configured.' . PHP_EOL);
    exit(1);
}

$sanitizeSegment = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
    $value = trim($value, '.-_');

    return $value !== '' ? $value : 'manual';
};

$label = $sanitizeSegment((string) ($options['label'] ?? 'manual'));
$timestamp = date('Ymd_His');
$safeDatabase = $sanitizeSegment($database);

$outputDirOption = (string) ($options['output-dir'] ?? 'storage/backups/database');
$outputDir = str_replace('\\', '/', $outputDirOption);
if (! str_starts_with($outputDir, '/') && ! preg_match('/^[A-Za-z]:\//', $outputDir)) {
    $outputDir = BASE_PATH . '/' . ltrim($outputDir, '/');
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0770, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, 'Could not create backup directory: ' . $outputDir . PHP_EOL);
    exit(1);
}

$resolveMysqldump = static function (?string $configuredPath): ?string {
    $candidates = [];

    if ($configuredPath !== null && trim($configuredPath) !== '') {
        $candidates[] = trim($configuredPath);
    }

    $envPath = getenv('MYSQLDUMP_PATH');
    if (is_string($envPath) && trim($envPath) !== '') {
        $candidates[] = trim($envPath);
    }

    $candidates[] = 'C:/xampp/mysql/bin/mysqldump.exe';
    $candidates[] = 'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe';
    $candidates[] = '/usr/bin/mysqldump';
    $candidates[] = '/usr/local/bin/mysqldump';
    $candidates[] = 'mysqldump';

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysqldump') {
            return $candidate;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
};

$mysqldump = $resolveMysqldump(isset($options['mysqldump']) ? (string) $options['mysqldump'] : null);
if ($mysqldump === null) {
    fwrite(STDERR, 'mysqldump was not found. Provide --mysqldump=PATH or set MYSQLDUMP_PATH.' . PHP_EOL);
    exit(1);
}

$backupFile = rtrim($outputDir, '/\\') . '/' . $safeDatabase . '_' . $timestamp . '_' . $label . '.sql';
$temporaryFile = $backupFile . '.tmp';
$manifestFile = $backupFile . '.json';

$command = [
    $mysqldump,
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--events',
    '--hex-blob',
    '--default-character-set=' . (string) ($config['charset'] ?? 'utf8mb4'),
    '--host=' . (string) ($config['host'] ?? '127.0.0.1'),
    '--port=' . (string) ($config['port'] ?? '3306'),
    '--user=' . (string) ($config['username'] ?? ''),
    $database,
];

$stdout = fopen($temporaryFile, 'wb');
if ($stdout === false) {
    fwrite(STDERR, 'Could not write backup file: ' . $temporaryFile . PHP_EOL);
    exit(1);
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => $stdout,
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
    fclose($stdout);
    @unlink($temporaryFile);
    fwrite(STDERR, 'Could not start mysqldump.' . PHP_EOL);
    exit(1);
}

fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
fclose($stdout);

$exitCode = proc_close($process);
if ($exitCode !== 0) {
    @unlink($temporaryFile);
    fwrite(STDERR, 'mysqldump failed with exit code ' . $exitCode . '.' . PHP_EOL);
    if (is_string($stderr) && trim($stderr) !== '') {
        fwrite(STDERR, trim($stderr) . PHP_EOL);
    }
    exit(1);
}

if (! is_file($temporaryFile) || filesize($temporaryFile) === 0) {
    @unlink($temporaryFile);
    fwrite(STDERR, 'Backup file was empty. Aborting.' . PHP_EOL);
    exit(1);
}

$renamed = @rename($temporaryFile, $backupFile);
if (! $renamed) {
    $copied = @copy($temporaryFile, $backupFile);
    if (! $copied) {
        @unlink($temporaryFile);
        fwrite(STDERR, 'Could not finalize backup file: ' . $backupFile . PHP_EOL);
        exit(1);
    }

    @unlink($temporaryFile);
}

if (! is_file($backupFile) || filesize($backupFile) === 0) {
    fwrite(STDERR, 'Final backup file was not created correctly.' . PHP_EOL);
    exit(1);
}

@chmod($backupFile, 0660);

$manifest = [
    'database' => $database,
    'created_at' => date(DATE_ATOM),
    'label' => $label,
    'file' => basename($backupFile),
    'bytes' => filesize($backupFile),
    'sha256' => hash_file('sha256', $backupFile),
    'host' => (string) ($config['host'] ?? ''),
    'port' => (string) ($config['port'] ?? ''),
];

file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
@chmod($manifestFile, 0660);

echo 'Database backup created: ' . $backupFile . PHP_EOL;
echo 'Backup manifest created: ' . $manifestFile . PHP_EOL;
