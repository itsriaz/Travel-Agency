<?php

declare(strict_types=1);

$cssPath = dirname(__DIR__) . '/public/assets/css/app.css';
$css = file_get_contents($cssPath);

if ($css === false) {
    fwrite(STDERR, "[FAIL] Could not read app.css.\n");
    exit(1);
}

$requiredFragments = [
    '--section-edge-accent: #249ac3',
    '.station-region,',
    '.financial-finder-card,',
    '.customer-advance-card,',
    '.legacy-payment-panel',
    '.app-shell .report-summary-group--pkr',
    '.app-shell .report-summary-group--aed',
    'pointer-events: none',
];

$missing = [];
foreach ($requiredFragments as $fragment) {
    if (! str_contains($css, $fragment)) {
        $missing[] = $fragment;
    }
}

if ($missing !== []) {
    fwrite(STDERR, '[FAIL] Missing shared section-accent CSS: ' . implode(', ', $missing) . "\n");
    exit(1);
}

if (str_contains($css, '.app-shell table::before')
    || str_contains($css, '.app-shell .dense-table::before')
    || str_contains($css, '.app-shell .stat-card::before { background: var(--section-edge-accent)')) {
    fwrite(STDERR, "[FAIL] Section accent leaked into tables or compact metric cards.\n");
    exit(1);
}

fwrite(STDOUT, "[PASS] Shared left-edge accents cover major form and report sections without decorating tables or compact metrics.\n");
