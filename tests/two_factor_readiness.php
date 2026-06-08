<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

$failures = [];

$check = static function (string $label, bool $passed): void {
    global $failures;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$setupMiddleware = is_file(BASE_PATH . '/app/Middleware/TwoFactorSetupRequiredMiddleware.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Middleware/TwoFactorSetupRequiredMiddleware.php')
    : '';
$verifyMiddleware = is_file(BASE_PATH . '/app/Middleware/TwoFactorVerifiedMiddleware.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Middleware/TwoFactorVerifiedMiddleware.php')
    : '';
$authController = is_file(BASE_PATH . '/app/Controllers/AuthController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/AuthController.php')
    : '';
$twoFactorService = is_file(BASE_PATH . '/app/Services/TwoFactorService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/TwoFactorService.php')
    : '';

$check(
    '2FA bypass is disabled in loaded configuration',
    (bool) config('security.two_factor.temporarily_disabled', true) === false
);

$check(
    'Protected routes require 2FA setup and verification',
    str_contains($publicIndex, 'TwoFactorSetupRequiredMiddleware::class')
        && str_contains($publicIndex, 'TwoFactorVerifiedMiddleware::class')
);

$check(
    '2FA setup middleware redirects unenrolled users to setup',
    str_contains($setupMiddleware, "url('/2fa/setup')")
        && str_contains($setupMiddleware, 'Auth::twoFactorTemporarilyDisabled()')
);

$check(
    '2FA verification middleware redirects unverified users to challenge',
    str_contains($verifyMiddleware, "url('/2fa/verify')")
        && str_contains($verifyMiddleware, 'Auth::twoFactorVerified()')
);

$check(
    '2FA setup, verification, and recovery routes are registered',
    str_contains($publicIndex, "/2fa/setup")
        && str_contains($publicIndex, "/2fa/verify")
        && str_contains($publicIndex, "/2fa/recovery-codes")
);

$check(
    '2FA controller and service support TOTP plus recovery codes',
    str_contains($authController, 'completeTwoFactorSetup')
        && str_contains($authController, 'verifyTwoFactor')
        && str_contains($twoFactorService, 'Google2FA')
        && str_contains($twoFactorService, 'TwoFactorRecoveryCodeRepository')
);

$check(
    '2FA QR label shows username before email for staff clarity',
    str_contains($twoFactorService, '$username . \' / \' . $email')
        && str_contains($twoFactorService, 'getQRCodeUrl($issuer, $label, $secret)')
);

if ($failures !== []) {
    echo PHP_EOL . '2FA readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . '2FA readiness passed.' . PHP_EOL;
