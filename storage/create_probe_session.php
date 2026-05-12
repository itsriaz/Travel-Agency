<?php

declare(strict_types=1);

use App\Core\App;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
$db = $app->get('db');

$statement = $db->query(
    'SELECT u.id, u.name, u.username, u.email, u.default_branch_id, u.session_version, r.code AS role_code
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
     ORDER BY u.id ASC
     LIMIT 1'
);

$user = $statement ? $statement->fetch() : null;
if (!is_array($user)) {
    fwrite(STDERR, "No active user found.\n");
    exit(1);
}

$branchIds = [];
try {
    $branchStatement = $db->prepare(
        'SELECT branch_id
         FROM user_branches
         WHERE user_id = :user_id
         ORDER BY branch_id ASC'
    );
    $branchStatement->execute(['user_id' => (int) $user['id']]);
    $branchIds = array_map(
        static fn (array $row): int => (int) $row['branch_id'],
        $branchStatement->fetchAll() ?: []
    );
} catch (Throwable) {
    $branchIds = [];
}

if ($branchIds === []) {
    $branchIds = [(int) $user['default_branch_id']];
}

$sessionId = 'codexprobe' . bin2hex(random_bytes(8));
session_save_path('C:\\xampp\\tmp');
session_name('travel_ops_session');
session_id($sessionId);
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => false,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => false,
]);

$_SESSION = [];
$_SESSION['auth_user'] = [
    'id' => (int) $user['id'],
    'name' => (string) $user['name'],
    'username' => (string) $user['username'],
    'email' => (string) $user['email'],
    'roleCode' => (string) $user['role_code'],
    'activeBranchId' => (int) $user['default_branch_id'],
    'accessibleBranchIds' => $branchIds,
    'mustChangePassword' => false,
    'sessionVersion' => (int) $user['session_version'],
    'twoFactorEnabled' => false,
    'twoFactorVerified' => true,
];
$_SESSION['_auth_last_activity'] = time();
session_write_close();

echo json_encode([
    'sessionName' => 'travel_ops_session',
    'sessionId' => $sessionId,
    'userId' => (int) $user['id'],
    'username' => (string) $user['username'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
