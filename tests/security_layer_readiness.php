<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$publicIndex = (string) file_get_contents(BASE_PATH . '/public/index.php');
$securityConfig = (string) file_get_contents(BASE_PATH . '/config/security.php');
$launcherGateMiddleware = (string) file_get_contents(BASE_PATH . '/app/Middleware/LauncherGateMiddleware.php');
$launcherMain = (string) file_get_contents(BASE_PATH . '/launcher/main.js');
$productionEnvExample = (string) file_get_contents(BASE_PATH . '/.env.production.example');
$appCore = (string) file_get_contents(BASE_PATH . '/app/Core/App.php');
$bootstrap = (string) file_get_contents(BASE_PATH . '/app/Core/bootstrap.php');
$session = (string) file_get_contents(BASE_PATH . '/app/Helpers/Session.php');
$authService = (string) file_get_contents(BASE_PATH . '/app/Services/AuthService.php');
$documentService = (string) file_get_contents(BASE_PATH . '/app/Services/DocumentWorkspaceService.php');
$loginAttemptRepository = (string) file_get_contents(BASE_PATH . '/app/Repositories/LoginAttemptRepository.php');
$securityThrottleRepository = (string) file_get_contents(BASE_PATH . '/app/Repositories/SecurityThrottleRepository.php');

$check(
    'Launcher gate middleware is globally applied before route dispatch',
    str_contains($publicIndex, 'LauncherGateMiddleware')
        && str_contains($publicIndex, '$requestPath !== \'/health\'')
        && strpos($publicIndex, 'LauncherGateMiddleware') < strpos($publicIndex, '$router->dispatch')
);
$check(
    'Launcher gate is enforced in production and supports signature-first rollout',
    str_contains($securityConfig, 'launcher_gate')
        && str_contains($appCore, 'LAUNCHER_GATE_ENABLED must be true in production')
        && str_contains($appCore, 'Launcher gate must use either signed requests or an explicitly enabled legacy token fallback.')
);
$check(
    'Launcher gate validates constant-time legacy token, signed launcher requests, and audits denials',
    str_contains($launcherGateMiddleware, 'hash_equals($expectedToken, $providedToken)')
        && str_contains($launcherGateMiddleware, 'openssl_verify(')
        && str_contains($launcherGateMiddleware, 'launcher_nonce_replayed')
        && str_contains($launcherGateMiddleware, 'security.launcher_gate.denied')
        && str_contains($launcherGateMiddleware, 'HTTP_')
);
$check(
    'Windows launcher sends launcher token/signature headers for navigation and API requests',
    str_contains($launcherMain, 'launcherTokenHeaders')
        && str_contains($launcherMain, 'launcherSignatureHeaders')
        && str_contains($launcherMain, 'webRequest.onBeforeSendHeaders')
        && str_contains($launcherMain, 'X-Travel-Launcher-Token')
        && str_contains($launcherMain, 'X-Travel-Launcher-Signature')
);
$check(
    'Production env template exposes launcher gate settings and explicit password hashing settings',
    str_contains($productionEnvExample, 'LAUNCHER_GATE_ENABLED=true')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_HEADER=')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_SIGNATURE_ENABLED=true')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_ALLOW_LEGACY_TOKEN=false')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_PUBLIC_KEY_PATH=')
        && str_contains($productionEnvExample, 'PASSWORD_HASH_DRIVER=argon2id')
);
$check(
    'Production hides detailed errors while logging real exceptions',
    str_contains($bootstrap, 'ini_set(\'display_errors\', $bootstrapDebug ? \'1\' : \'0\')')
        && str_contains($bootstrap, 'app_log_exception($exception,')
        && str_contains($bootstrap, 'app_debug_tools_enabled()')
);
$check(
    'Sessions use HttpOnly, Secure config, SameSite, strict mode, and ID regeneration',
    str_contains($session, "'cookie_httponly' => true")
        && str_contains($session, "'cookie_secure' =>")
        && str_contains($session, "'cookie_samesite' =>")
        && str_contains($session, "'use_strict_mode' => true")
        && str_contains($session, 'session_regenerate_id(true)')
);
$check(
    'Login throttling and login audit remain active',
    str_contains($authService, 'countRecentFailures')
        && str_contains($authService, 'auth.login.failed')
        && str_contains($authService, 'auth.login.locked')
        && str_contains($authService, 'auth.login')
);
$check(
    'Security throttles use app-side attempted_at timestamps instead of database NOW()',
    str_contains($loginAttemptRepository, 'currentAttemptedAt')
        && str_contains($loginAttemptRepository, ':attempted_at')
        && ! str_contains($loginAttemptRepository, 'NOW())')
        && str_contains($securityThrottleRepository, 'currentAttemptedAt')
        && str_contains($securityThrottleRepository, ':attempted_at')
        && ! str_contains($securityThrottleRepository, 'NOW())')
);
$check(
    'Passwords use explicit hasher policy with automatic rehash support',
    is_file(BASE_PATH . '/app/Helpers/PasswordHasher.php')
        && str_contains($authService, 'PasswordHasher::needsRehash')
        && str_contains($securityConfig, "'hash_driver' =>")
);
$check(
    'Document uploads are restricted to non-executable file types',
    str_contains($securityConfig, "'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp']")
        && ! str_contains($securityConfig, "'php'")
        && str_contains($documentService, 'allowed_extensions')
        && str_contains($documentService, 'allowed_mime_types')
);

if ($failures !== []) {
    echo PHP_EOL . 'Security layer readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Security layer readiness passed.' . PHP_EOL;
