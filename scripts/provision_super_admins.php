<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);

$options = getopt('', [
    'apply',
    'help',
]);

if (isset($options['help'])) {
    echo 'Usage: php scripts/provision_super_admins.php [--apply]' . PHP_EOL;
    echo 'Without --apply, this command prints the target super-admin accounts only.' . PHP_EOL;
    exit(0);
}

$superAdmins = [
    ['id' => 1, 'name' => 'Dr. Muhammad Munir', 'username' => 'm.munir', 'email' => 'munir@travelagency.local'],
    ['id' => 2, 'name' => 'Imdad Ullah', 'username' => 'imdad.ullah', 'email' => 'imdad.ullah@travelagency.local'],
    ['id' => 3, 'name' => 'Salman Faiz', 'username' => 'salman.faiz', 'email' => 'salman.faiz@travelagency.local'],
    ['id' => 4, 'name' => 'Abubakar', 'username' => 'abubakar', 'email' => 'abubakar@travelagency.local'],
    ['id' => 5, 'name' => 'Fida Hussain Khan', 'username' => 'fida.hussain', 'email' => 'fida.hussain@travelagency.local'],
    ['id' => 10, 'name' => 'Development Super Admin', 'username' => 'superadmin', 'email' => 'superadmin@travelagency.local'],
];

echo 'Target super-admin accounts:' . PHP_EOL;
foreach ($superAdmins as $superAdmin) {
    echo '- ' . $superAdmin['name'] . ' / ' . $superAdmin['username'] . ' / ' . $superAdmin['email'] . PHP_EOL;
}
echo 'Temporary password for all accounts: ChangeMeNow!123 (must change on first login)' . PHP_EOL;

if (! isset($options['apply'])) {
    echo PHP_EOL . 'No database changes applied. Re-run with --apply to provision these users via database seeding.' . PHP_EOL;
    exit(0);
}

/** @var PDO $db */
$db = $app->get('db');
$seed = require BASE_PATH . '/database/seeds/20260413_000001_foundation_seed.php';
$seed($db);
$targetIds = array_map(static fn (array $row): int => (int) $row['id'], $superAdmins);
$targetIdList = implode(',', $targetIds);
$deactivateStatement = $db->prepare(
    'UPDATE users
     SET is_active = 0,
         updated_at = NOW()
     WHERE id NOT IN (' . $targetIdList . ')'
);
$deactivateStatement->execute();

echo PHP_EOL . 'Super-admin provisioning applied successfully.' . PHP_EOL;
