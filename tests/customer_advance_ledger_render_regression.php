<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$_SERVER['REQUEST_URI'] = '/reports?report=customer_advance_ledger';

$html = (new \App\Core\View($app))->render('reports/index', [
    'selectedReport' => 'customer_advance_ledger',
    'reportOptions' => ['customer_advance_ledger' => 'Customer Advance Ledger'],
    'filters' => ['asOfDate' => '2026-07-16'],
    'columns' => [],
    'rows' => [],
    'summaryCards' => [],
]);

$failures = [];
if (! str_contains($html, '<!doctype html>')) {
    $failures[] = 'Application layout was not rendered.';
}
if (! str_contains($html, 'assets/css/app.css')) {
    $failures[] = 'Application stylesheet was not linked.';
}
if (! str_contains($html, 'name="_token"')) {
    $failures[] = 'Customer advance correction form has no CSRF input.';
}
if (! str_contains($html, 'report-stat-grid--customer-advance')) {
    $failures[] = 'Customer advance summary does not use the compact one-row card grid.';
}
if (str_contains($html, 'Csrf::field')) {
    $failures[] = 'Invalid CSRF helper reference remains in rendered output.';
}

if ($failures !== []) {
    fwrite(STDERR, "Customer Advance Ledger render regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer Advance Ledger renders inside the styled application layout with CSRF protection.' . PHP_EOL;
