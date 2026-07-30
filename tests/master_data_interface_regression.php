<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/control/master_data.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$panel = (string) file_get_contents($root . '/app/Views/control/partials/register_panel.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$checks = [
    'Master Data has one consolidated navigator' => str_contains($view, 'master-data-navigator')
        && str_contains($view, 'master-data-nav__group')
        && str_contains($view, 'Management')
        && str_contains($view, 'Registers'),
    'Management tools use one consistent navigation style' => substr_count($view, 'class="master-data-nav-link"') >= 4,
    'Redundant Booking Workspace action was removed' => ! str_contains($view, '>Open Booking Workspace</a>'),
    'Supplier and account tools remain accessible' => str_contains($view, '>Add Supplier</a>')
        && str_contains($view, '>Manage Suppliers</a>')
        && str_contains($view, '>Account and Supplier Links</a>'),
    'Redundant settlement navigation was removed' => ! str_contains($view, '>Account and Supplier Settlements</a>'),
    'Accounting Engine is hidden from normal navigation' => ! str_contains($view, '>Accounting Engine</a>')
        && ! str_contains($layout, '>Accounting Engine</a>'),
    'Supplier Modes remains implemented but is hidden from Master Data' => str_contains($view, "!== 'supplier_modes'")
        && str_contains((string) file_get_contents($root . '/app/Controllers/ControlController.php'), "'supplier_modes'")
        && str_contains((string) file_get_contents($root . '/app/Repositories/MasterDataRepository.php'), "'supplier_modes'"),
    'Register panels alternate visual tones' => str_contains($view, '$registerTone')
        && str_contains($panel, 'admin-register-panel--'),
    'Register rows have alternating colors' => str_contains($css, '.admin-register-panel .dense-table tbody tr:nth-child(odd) td')
        && str_contains($css, '.admin-register-panel .dense-table tbody tr:nth-child(even) td'),
    'Register table no longer forces every cell onto one line' => str_contains($css, '.admin-register-table .dense-table td')
        && str_contains($css, 'white-space: normal;')
        && str_contains($css, '.admin-register-table {')
        && str_contains($css, 'overflow: hidden;'),
    'Narrow layouts stack the editor below the table' => str_contains($css, '@media (max-width: 1320px)')
        && str_contains($css, '.admin-register-form')
        && str_contains($css, 'max-width: 720px;'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . 'Master Data interface regression failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Master Data interface regression passed.' . PHP_EOL;
