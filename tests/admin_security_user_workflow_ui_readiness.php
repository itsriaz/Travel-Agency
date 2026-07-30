<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SecurityController.php');
$view = (string) file_get_contents($root . '/app/Views/security/admin_panel.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$checks = [
    'Edit User has a separate protected route' => str_contains($routes, "'/admin/security/users/edit'")
        && str_contains($routes, 'SuperAdminMiddleware::class'),
    'Create and edit workflows are separated by presentation mode' => str_contains($view, 'if (!$editMode)')
        && str_contains($view, '>Edit User</a>')
        && str_contains($view, "url('/admin/security/users/edit')")
        && !str_contains($view, '>Load</button>')
        && str_contains($view, "picker.addEventListener('change'")
        && str_contains($view, 'pickerForm.requestSubmit()'),
    'Create page no longer loads a selected user inline' => str_contains($controller, "'editMode' => false")
        && str_contains($controller, "'users' => []")
        && str_contains($controller, "'target' => null"),
    'Existing edit actions return to the dedicated editor' => substr_count($controller, "'/admin/security/users/edit?user_id='") >= 6,
    'Legacy selected-user links remain backward compatible' => str_contains($controller, '$legacySelectedUserId')
        && str_contains($controller, "redirect('/admin/security/users/edit?user_id='"),
    'Security user forms have dedicated professional styling' => str_contains($css, '.security-admin-picker-panel')
        && str_contains($css, '.security-admin-create-panel')
        && str_contains($css, 'grid-template-columns: minmax(360px, 1fr);')
        && str_contains($view, 'security-user-details-form')
        && str_contains($css, '#loaded-user-record .security-user-details-form')
        && str_contains($css, 'grid-template-columns: repeat(3, minmax(0, 1fr));'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . 'Admin security user workflow UI readiness failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Admin security user workflow UI readiness passed.' . PHP_EOL;
