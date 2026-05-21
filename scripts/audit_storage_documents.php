<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

/** @var PDO $db */
$db = app()->get('db');
$storageRoot = realpath(BASE_PATH . '/storage/documents') ?: BASE_PATH . '/storage/documents';
$storageRootNormalized = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';

$failures = [];
$warnings = [];

$normalizeStoragePath = static function (string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');

    return str_starts_with($path, 'documents/') ? $path : 'documents/' . $path;
};

$resolveStoredPath = static function (string $relativePath): string|false {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $candidates = [
        BASE_PATH . '/storage/' . $relativePath,
        BASE_PATH . '/storage/' . preg_replace('#^documents/#', '', $relativePath),
    ];

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false) {
            return $resolved;
        }
    }

    return false;
};

$dbFiles = [];
$recordRows = [];

foreach ([
    'booking_documents' => 'Booking Document',
    'expense_attachments' => 'Expense Attachment',
] as $table => $label) {
    $statement = $db->query(
        'SELECT id, storage_path, file_size_bytes, sha256_hash, status
         FROM `' . $table . '`
         WHERE status <> "revoked"'
    );
    $rows = $statement !== false ? $statement->fetchAll() : [];

    foreach ($rows as $row) {
        $relativePath = $normalizeStoragePath((string) ($row['storage_path'] ?? ''));
        $recordRows[] = [
            'label' => $label,
            'id' => (int) ($row['id'] ?? 0),
            'relative_path' => $relativePath,
            'absolute_path' => $resolveStoredPath($relativePath),
            'file_size_bytes' => (int) ($row['file_size_bytes'] ?? 0),
            'sha256_hash' => (string) ($row['sha256_hash'] ?? ''),
        ];
    }
}

foreach ($recordRows as $record) {
    $absolutePath = $record['absolute_path'];
    if (! is_string($absolutePath) || $absolutePath === '') {
        $failures[] = $record['label'] . ' #' . $record['id'] . ' missing file: ' . $record['relative_path'];
        continue;
    }

    $normalizedAbsolute = str_replace('\\', '/', $absolutePath);
    if (! str_starts_with($normalizedAbsolute, $storageRootNormalized)) {
        $failures[] = $record['label'] . ' #' . $record['id'] . ' resolves outside storage/documents.';
        continue;
    }

    $relativeFromRoot = substr($normalizedAbsolute, strlen($storageRootNormalized));
    $dbFiles[$relativeFromRoot] = true;

    $actualSize = filesize($absolutePath);
    if ($actualSize !== false && (int) $record['file_size_bytes'] > 0 && (int) $actualSize !== (int) $record['file_size_bytes']) {
        $failures[] = $record['label'] . ' #' . $record['id'] . ' file size mismatch.';
    }

    $actualHash = hash_file('sha256', $absolutePath);
    if ($actualHash !== false && $record['sha256_hash'] !== '' && ! hash_equals($record['sha256_hash'], $actualHash)) {
        $failures[] = $record['label'] . ' #' . $record['id'] . ' SHA-256 mismatch.';
    }
}

$storageFiles = [];
if (is_dir($storageRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $fileInfo->getPathname());
        $relative = substr($path, strlen($storageRootNormalized));
        if ($relative === '.gitkeep') {
            continue;
        }

        $storageFiles[$relative] = true;
    }
}

$orphanFiles = array_values(array_diff(array_keys($storageFiles), array_keys($dbFiles)));
foreach (array_slice($orphanFiles, 0, 25) as $orphanFile) {
    $warnings[] = 'Orphan storage file: documents/' . $orphanFile;
}

echo '[INFO] Active database file records: ' . count($recordRows) . PHP_EOL;
echo '[INFO] Files on disk: ' . count($storageFiles) . PHP_EOL;
echo '[INFO] Orphan files: ' . count($orphanFiles) . PHP_EOL;

foreach ($warnings as $warning) {
    echo '[WARN] ' . $warning . PHP_EOL;
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo '[FAIL] ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Storage document audit passed.' . PHP_EOL;
