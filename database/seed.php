<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$seedFiles = glob(BASE_PATH . '/database/seeds/*.php') ?: [];

foreach ($seedFiles as $seedFile) {
    $seed = require $seedFile;
    $seed($db);
    echo 'Seeded: ' . basename($seedFile) . PHP_EOL;
}

echo 'Seeding complete.' . PHP_EOL;
