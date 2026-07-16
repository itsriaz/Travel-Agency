<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

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

$artifactCheck = app_is_production() ? $warn : $check;
$readFile = static function (string $path): string {
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$rootIndex = $readFile(BASE_PATH . '/index.php');
$publicIndex = $readFile(BASE_PATH . '/public/index.php');
$frontController = $publicIndex !== '' ? $publicIndex : $rootIndex;
$securityConfig = $readFile(BASE_PATH . '/config/security.php');
$launcherGateMiddleware = $readFile(BASE_PATH . '/app/Middleware/LauncherGateMiddleware.php');
$launcherMain = $readFile(BASE_PATH . '/launcher/main.js');
$productionEnvExample = $readFile(BASE_PATH . '/.env.production.example');
$appCore = $readFile(BASE_PATH . '/app/Core/App.php');
$bootstrap = $readFile(BASE_PATH . '/app/Core/bootstrap.php');
$session = $readFile(BASE_PATH . '/app/Helpers/Session.php');
$authService = $readFile(BASE_PATH . '/app/Services/AuthService.php');
$documentService = $readFile(BASE_PATH . '/app/Services/DocumentWorkspaceService.php');
$loginAttemptRepository = $readFile(BASE_PATH . '/app/Repositories/LoginAttemptRepository.php');
$securityThrottleRepository = $readFile(BASE_PATH . '/app/Repositories/SecurityThrottleRepository.php');

$artifactCheck(
    'Front controller keeps launcher gate middleware ahead of route dispatch',
    $frontController !== ''
        && str_contains($frontController, 'LauncherGateMiddleware')
        && str_contains($frontController, '$requestPath !== \'/health\'')
        && strpos($frontController, 'LauncherGateMiddleware') < strpos($frontController, '$router->dispatch'),
    $frontController === '' ? 'No root or public front controller file was found on this host.' : ''
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
$artifactCheck(
    'Windows launcher sends launcher token/signature headers for navigation and API requests',
    $launcherMain !== ''
        && str_contains($launcherMain, 'launcherTokenHeaders')
        && str_contains($launcherMain, 'launcherSignatureHeaders')
        && str_contains($launcherMain, 'webRequest.onBeforeSendHeaders')
        && str_contains($launcherMain, 'X-Travel-Launcher-Token')
        && str_contains($launcherMain, 'X-Travel-Launcher-Signature'),
    $launcherMain === '' ? 'launcher/main.js is not deployed on this host.' : ''
);
$artifactCheck(
    'Production env template exposes launcher gate settings and explicit password hashing settings',
    $productionEnvExample !== ''
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_ENABLED=true')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_HEADER=')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_SIGNATURE_ENABLED=true')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_ALLOW_LEGACY_TOKEN=false')
        && str_contains($productionEnvExample, 'LAUNCHER_GATE_PUBLIC_KEY_PATH=')
        && str_contains($productionEnvExample, 'PASSWORD_HASH_DRIVER=argon2id'),
    $productionEnvExample === '' ? '.env.production.example is not deployed on this host.' : ''
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
    return 1;
}

echo PHP_EOL . 'Security layer readiness passed.' . PHP_EOL;
return 0;
