<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$command = strtolower(trim((string) ($argv[1] ?? 'up')));
$validCommands = ['up', 'status'];
if (! in_array($command, $validCommands, true)) {
    fwrite(STDERR, 'Usage: php database/migrate.php [up|status]' . PHP_EOL);
    exit(1);
}

$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(190) NOT NULL UNIQUE,
        executed_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$executed = $db->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$migrationFiles = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
$migrationNames = array_map(static fn (string $path): string => basename($path), $migrationFiles);
$pending = array_values(array_diff($migrationNames, $executed));

if ($command === 'status') {
    echo 'Applied migrations: ' . count($executed) . PHP_EOL;
    echo 'Pending migrations: ' . count($pending) . PHP_EOL;

    if ($pending !== []) {
        echo PHP_EOL . 'Pending:' . PHP_EOL;
        foreach ($pending as $migrationName) {
            echo ' - ' . $migrationName . PHP_EOL;
        }
    }

    exit(0);
}

if ($pending === []) {
    echo 'No pending migrations.' . PHP_EOL;
    exit(0);
}

foreach ($migrationFiles as $migrationFile) {
    $migrationName = basename($migrationFile);

    if (in_array($migrationName, $executed, true)) {
        continue;
    }

    $migration = require $migrationFile;

    try {
        if (! $db->inTransaction()) {
            $db->beginTransaction();
        }

        foreach ($migration['up'] as $sql) {
            if (is_callable($sql)) {
                $sql($db);
                continue;
            }

            $db->exec($sql);
        }

        $statement = $db->prepare('INSERT INTO migrations (migration_name, executed_at) VALUES (:migration_name, NOW())');
        $statement->execute(['migration_name' => $migrationName]);
        if ($db->inTransaction()) {
            $db->commit();
        }
        echo "Migrated: {$migrationName}" . PHP_EOL;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        echo "Failed: {$migrationName} - {$exception->getMessage()}" . PHP_EOL;
        exit(1);
    }
}

echo 'Migrations complete.' . PHP_EOL;
