<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

$failures = [];
$warnings = [];

$line = static function (string $level, string $label, string $details = ''): void {
    echo '[' . $level . '] ' . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
};

$check = static function (string $label, bool $passed, string $details = '') use (&$failures, $line): void {
    $line($passed ? 'PASS' : 'FAIL', $label, $details);

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$warn = static function (string $label, bool $passed, string $details = '') use (&$warnings, $line): void {
    $line($passed ? 'PASS' : 'WARN', $label, $details);

    if (! $passed) {
        $warnings[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$env = strtolower(trim((string) config('app.env', 'local')));
$isProduction = $env === 'production';
$appUrl = trim((string) config('app.url', ''));
$appDebug = (bool) config('app.debug', false);
$appKey = trim((string) config('app.key', ''));
$healthToken = trim((string) config('security.health.token', ''));
$resetDeliveryMode = strtolower(trim((string) config('security.reset.delivery_mode', '')));
$twoFactorDisabled = (bool) config('security.two_factor.temporarily_disabled', false);
$sessionSecure = (bool) config('security.session.cookie_secure', false);
$trustedDeviceSecure = (bool) config('security.trusted_device.cookie_secure', false);
$hstsEnabled = (bool) config('security.headers.hsts_enabled', false);

$defaultAppKey = 'base64:Wm5uWGQ0blFSbVQ4ME5hL2p3VVRYeG9NcnN0Qk9ud3pPaGRYRE1vV0d6TT0=';
$placeholderAppKey = 'base64:REPLACE_WITH_A_REAL_32_BYTE_BASE64_KEY';
$decodedAppKey = str_starts_with($appKey, 'base64:')
    ? base64_decode(substr($appKey, 7), true)
    : false;

echo 'Travel Agency environment configuration audit' . PHP_EOL;
echo 'Environment: ' . $env . PHP_EOL;
echo 'URL: ' . ($appUrl !== '' ? $appUrl : '(not configured)') . PHP_EOL . PHP_EOL;

$appKeyIsValid = is_string($decodedAppKey) && strlen($decodedAppKey) === 32;
$keyIsNotDefault = $appKey !== '' && $appKey !== $defaultAppKey && $appKey !== $placeholderAppKey;

if ($isProduction) {
    $check('Production APP_KEY is a base64 32-byte key', $appKeyIsValid);
    $check('Production APP_KEY is not the bundled/default key', $keyIsNotDefault);
    $check('Production APP_DEBUG is false', ! $appDebug);
    $check('Production APP_URL uses HTTPS', str_starts_with(strtolower($appUrl), 'https://'), $appUrl);
    $check('Production session cookie is secure', $sessionSecure);
    $check('Production trusted-device cookie is secure', $trustedDeviceSecure);
    $check('Production HSTS is enabled', $hstsEnabled);
    $check('Production two-factor bypass is disabled', ! $twoFactorDisabled);
    $check('Production password reset delivery is not log mode', $resetDeliveryMode !== 'log', $resetDeliveryMode);
    $check('Production health check token is configured', $healthToken !== '');
    $check('Production health check token is not a placeholder', ! str_contains(strtolower($healthToken), 'replace_with'));
} else {
    $warn('Non-production APP_KEY is a base64 32-byte key', $appKeyIsValid, 'required before production');
    $warn('Non-production APP_KEY has been customized', $keyIsNotDefault, 'generate a real key before production');
    $warn('Non-production APP_DEBUG may be true', ! $appDebug, 'expected during local development');
}

$databaseName = trim((string) config('database.database', ''));
$databaseUser = trim((string) config('database.username', ''));
$databasePassword = (string) config('database.password', '');
$check('Database name is configured', $databaseName !== '');

if ($isProduction) {
    $check('Production DB name is not a placeholder/default', ! in_array($databaseName, ['travel_agency_ops', 'replace_with_production_database'], true), $databaseName);
    $check('Production DB user is not root/placeholder', ! in_array($databaseUser, ['root', 'replace_with_production_user', ''], true), $databaseUser);
    $check('Production DB password is configured', trim($databasePassword) !== '');
    $check('Production DB password is not placeholder', ! str_contains(strtolower($databasePassword), 'replace_with'));
}

$publicEnvPath = BASE_PATH . '/public/.env';
$rootEnvPath = BASE_PATH . '/.env';
$check('No .env file exists inside public web root', ! is_file($publicEnvPath));
$warn('Root .env file exists for deployment config', is_file($rootEnvPath), 'Hostinger should use a real .env outside public access');

if ($warnings !== []) {
    echo PHP_EOL . 'Warnings:' . PHP_EOL;
    foreach ($warnings as $warning) {
        echo ' - ' . $warning . PHP_EOL;
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Environment configuration audit failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Environment configuration audit passed.' . PHP_EOL;
