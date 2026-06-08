<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

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
    'offsite-dir::',
    'skip-documents',
    'prune',
    'keep-daily::',
    'keep-weekly::',
    'keep-monthly::',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/run_backup_cycle.php [--label=nightly] [--offsite-dir=E:\TravelAgency-Offsite] [--skip-documents] [--prune] [--keep-daily=14] [--keep-weekly=8] [--keep-monthly=12]' . PHP_EOL;
    exit(0);
}

$sanitizeSegment = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
    $value = trim($value, '.-_');

    return $value !== '' ? $value : 'manual';
};

$resolvePath = static function (string $path) use ($sanitizeSegment): string {
    $normalized = str_replace('\\', '/', trim($path));
    if ($normalized === '') {
        return BASE_PATH;
    }

    if (! str_starts_with($normalized, '/') && ! preg_match('/^[A-Za-z]:\//', $normalized)) {
        return BASE_PATH . '/' . ltrim($normalized, '/');
    }

    return $normalized;
};

$ensureDirectory = static function (string $path): void {
    if (is_dir($path)) {
        return;
    }

    if (! mkdir($path, 0770, true) && ! is_dir($path)) {
        throw new RuntimeException('Could not create directory: ' . $path);
    }
};

$copyFile = static function (string $source, string $destination) use ($ensureDirectory): void {
    $ensureDirectory(dirname($destination));
    if (! copy($source, $destination)) {
        throw new RuntimeException('Could not copy file: ' . $source . ' -> ' . $destination);
    }
};

$copyDirectory = static function (string $source, string $destination) use ($ensureDirectory): array {
    $fileCount = 0;
    $totalBytes = 0;
    $ensureDirectory($destination);

    if (! is_dir($source)) {
        return ['files' => 0, 'bytes' => 0];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $relativePath = substr($sourcePath, strlen($source) + 1);
        $targetPath = $destination . '/' . str_replace('\\', '/', $relativePath);

        if ($item->isDir()) {
            $ensureDirectory($targetPath);
            continue;
        }

        $ensureDirectory(dirname($targetPath));
        if (! copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Could not copy file: ' . $sourcePath . ' -> ' . $targetPath);
        }

        $fileCount++;
        $totalBytes += (int) $item->getSize();
    }

    return ['files' => $fileCount, 'bytes' => $totalBytes];
};

$removeDirectory = static function (string $path): void {
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            if (! rmdir($item->getPathname())) {
                throw new RuntimeException('Could not remove directory: ' . $item->getPathname());
            }
            continue;
        }

        if (! unlink($item->getPathname())) {
            throw new RuntimeException('Could not remove file: ' . $item->getPathname());
        }
    }

    if (! rmdir($path)) {
        throw new RuntimeException('Could not remove directory: ' . $path);
    }
};

$buildRetentionPlan = static function (string $cyclesRoot, int $keepDaily, int $keepWeekly, int $keepMonthly): array {
    if (! is_dir($cyclesRoot)) {
        return ['keep' => [], 'delete' => []];
    }

    $entries = [];
    foreach (new DirectoryIterator($cyclesRoot) as $item) {
        if (! $item->isDir() || $item->isDot()) {
            continue;
        }

        if (! preg_match('/^(?<stamp>\d{8}_\d{6})_/', $item->getFilename(), $matches)) {
            continue;
        }

        $stamp = $matches['stamp'];
        $date = DateTimeImmutable::createFromFormat('Ymd_His', $stamp);
        if (! $date instanceof DateTimeImmutable) {
            continue;
        }

        $entries[] = [
            'name' => $item->getFilename(),
            'path' => $item->getPathname(),
            'date' => $date,
            'dateKey' => $date->format('Y-m-d'),
            'weekKey' => $date->format('o-\WW'),
            'monthKey' => $date->format('Y-m'),
            'stamp' => $stamp,
        ];
    }

    usort($entries, static fn (array $left, array $right): int => strcmp($right['stamp'], $left['stamp']));

    $keep = [];
    $seenDates = [];
    $seenWeeks = [];
    $seenMonths = [];
    $dailyDates = 0;
    $weeklyWeeks = 0;
    $monthlyMonths = 0;

    foreach ($entries as $entry) {
        if ($dailyDates < $keepDaily && ! isset($seenDates[$entry['dateKey']])) {
            $seenDates[$entry['dateKey']] = true;
            $keep[$entry['name']] = true;
            $dailyDates++;
            continue;
        }

        if ($weeklyWeeks < $keepWeekly && ! isset($seenWeeks[$entry['weekKey']])) {
            $seenWeeks[$entry['weekKey']] = true;
            $keep[$entry['name']] = true;
            $weeklyWeeks++;
            continue;
        }

        if ($monthlyMonths < $keepMonthly && ! isset($seenMonths[$entry['monthKey']])) {
            $seenMonths[$entry['monthKey']] = true;
            $keep[$entry['name']] = true;
            $monthlyMonths++;
            continue;
        }
    }

    $delete = [];
    foreach ($entries as $entry) {
        if (! isset($keep[$entry['name']])) {
            $delete[] = $entry['path'];
        }
    }

    return [
        'keep' => array_keys($keep),
        'delete' => $delete,
    ];
};

$runLabel = $sanitizeSegment((string) ($options['label'] ?? 'nightly'));
$keepDaily = max(1, (int) ($options['keep-daily'] ?? 14));
$keepWeekly = max(0, (int) ($options['keep-weekly'] ?? 8));
$keepMonthly = max(0, (int) ($options['keep-monthly'] ?? 12));
$includeDocuments = ! isset($options['skip-documents']);
$prune = isset($options['prune']);
$offsiteDirOption = isset($options['offsite-dir']) ? trim((string) $options['offsite-dir']) : '';

$cyclesRoot = BASE_PATH . '/storage/backups/cycles';
$ensureDirectory($cyclesRoot);

$timestamp = date('Ymd_His');
$cycleName = $timestamp . '_' . $runLabel;
$cycleDir = $cyclesRoot . '/' . $cycleName;
$cycleDatabaseDir = $cycleDir . '/database';
$cycleDocumentsDir = $cycleDir . '/documents';

$ensureDirectory($cycleDatabaseDir);
$ensureDirectory($cycleDocumentsDir);

$databaseBackupOutput = [];
$databaseBackupExit = 1;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/backup_database.php') . ' --label=' . escapeshellarg($runLabel),
    $databaseBackupOutput,
    $databaseBackupExit
);

if ($databaseBackupExit !== 0) {
    fwrite(STDERR, 'Database backup failed.' . PHP_EOL . implode(PHP_EOL, $databaseBackupOutput) . PHP_EOL);
    exit(1);
}

$backupFile = null;
$backupManifest = null;
foreach ($databaseBackupOutput as $line) {
    if (str_starts_with($line, 'Database backup created: ')) {
        $backupFile = trim(substr($line, strlen('Database backup created: ')));
    }
    if (str_starts_with($line, 'Backup manifest created: ')) {
        $backupManifest = trim(substr($line, strlen('Backup manifest created: ')));
    }
}

if (! is_string($backupFile) || $backupFile === '' || ! is_file($backupFile)) {
    fwrite(STDERR, 'Could not resolve backup SQL file from backup_database.php output.' . PHP_EOL);
    exit(1);
}

if (! is_string($backupManifest) || $backupManifest === '' || ! is_file($backupManifest)) {
    fwrite(STDERR, 'Could not resolve backup manifest from backup_database.php output.' . PHP_EOL);
    exit(1);
}

$copiedDatabaseFile = $cycleDatabaseDir . '/' . basename($backupFile);
$copiedManifestFile = $cycleDatabaseDir . '/' . basename($backupManifest);
$copyFile($backupFile, $copiedDatabaseFile);
$copyFile($backupManifest, $copiedManifestFile);

$documentsSource = BASE_PATH . '/storage/documents';
$documentsStats = ['files' => 0, 'bytes' => 0];
if ($includeDocuments) {
    $documentsStats = $copyDirectory($documentsSource, $cycleDocumentsDir);
}

$cycleManifest = [
    'cycle_name' => $cycleName,
    'created_at' => date(DATE_ATOM),
    'label' => $runLabel,
    'database_backup' => [
        'source_file' => $backupFile,
        'copied_file' => $copiedDatabaseFile,
        'manifest_file' => $copiedManifestFile,
        'sha256' => hash_file('sha256', $copiedDatabaseFile),
        'bytes' => filesize($copiedDatabaseFile),
    ],
    'documents_snapshot' => [
        'included' => $includeDocuments,
        'source_dir' => $documentsSource,
        'snapshot_dir' => $cycleDocumentsDir,
        'file_count' => $documentsStats['files'],
        'total_bytes' => $documentsStats['bytes'],
    ],
    'retention' => [
        'daily' => $keepDaily,
        'weekly' => $keepWeekly,
        'monthly' => $keepMonthly,
        'prune_applied' => $prune,
    ],
    'offsite_copy' => null,
];

$offsiteCopySummary = null;
if ($offsiteDirOption !== '') {
    $offsiteRoot = $resolvePath($offsiteDirOption);
    $offsiteCycleDir = rtrim($offsiteRoot, '/\\') . '/' . $cycleName;
    $offsiteCopyStats = $copyDirectory($cycleDir, $offsiteCycleDir);
    $offsiteCopySummary = [
        'root_dir' => $offsiteRoot,
        'cycle_dir' => $offsiteCycleDir,
        'file_count' => $offsiteCopyStats['files'],
        'total_bytes' => $offsiteCopyStats['bytes'],
    ];
    $cycleManifest['offsite_copy'] = $offsiteCopySummary;
}

$manifestPath = $cycleDir . '/cycle_manifest.json';
file_put_contents($manifestPath, json_encode($cycleManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

$pruned = [
    'local' => [],
    'offsite' => [],
];

if ($prune) {
    $localRetentionPlan = $buildRetentionPlan($cyclesRoot, $keepDaily, $keepWeekly, $keepMonthly);
    foreach ($localRetentionPlan['delete'] as $deletePath) {
        if ($deletePath === $cycleDir) {
            continue;
        }
        $removeDirectory($deletePath);
        $pruned['local'][] = $deletePath;
    }

    if ($offsiteCopySummary !== null) {
        $offsitePlan = $buildRetentionPlan($offsiteCopySummary['root_dir'], $keepDaily, $keepWeekly, $keepMonthly);
        foreach ($offsitePlan['delete'] as $deletePath) {
            if ($deletePath === $offsiteCopySummary['cycle_dir']) {
                continue;
            }
            $removeDirectory($deletePath);
            $pruned['offsite'][] = $deletePath;
        }
    }
}

echo 'Backup cycle created: ' . $cycleDir . PHP_EOL;
echo 'Database backup copied to: ' . $copiedDatabaseFile . PHP_EOL;
echo 'Documents included: ' . ($includeDocuments ? 'yes' : 'no') . PHP_EOL;
if ($includeDocuments) {
    echo 'Document snapshot files: ' . $documentsStats['files'] . PHP_EOL;
}
if ($offsiteCopySummary !== null) {
    echo 'Offsite copy created: ' . $offsiteCopySummary['cycle_dir'] . PHP_EOL;
}
if ($prune) {
    echo 'Pruned local cycles: ' . count($pruned['local']) . PHP_EOL;
    if ($offsiteCopySummary !== null) {
        echo 'Pruned offsite cycles: ' . count($pruned['offsite']) . PHP_EOL;
    }
}
echo 'Cycle manifest: ' . $manifestPath . PHP_EOL;
