<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

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

$buildScript = BASE_PATH . '/scripts/build_release_package.ps1';
$buildScriptSource = is_file($buildScript)
    ? (string) file_get_contents($buildScript)
    : '';

$check('Release package builder exists', is_file($buildScript));
$check(
    'Release package builder creates clean storage directories instead of copying runtime storage',
    str_contains($buildScriptSource, '$StorageDir = Join-Path $PackageDir "storage"')
        && ! str_contains($buildScriptSource, 'Copy-Item -LiteralPath (Join-Path $Root "storage")')
);
$check(
    'Release package builder copies only tracked or intentional non-ignored files from working directories',
    str_contains($buildScriptSource, 'git -C $Root ls-files --cached --others --exclude-standard -- $RelativeDirectory')
);

$gitignorePath = BASE_PATH . '/.gitignore';
$gitignoreSource = is_file($gitignorePath)
    ? (string) file_get_contents($gitignorePath)
    : '';

foreach ([
    'storage/regression_screenshots/',
    'storage/pw-temp/',
    'storage/release-packages/',
] as $ignoredEntry) {
    $check(
        '.gitignore covers ' . $ignoredEntry,
        str_contains($gitignoreSource, $ignoredEntry)
    );
}

$unexpectedPaths = [
    'Account Statement,',
    'Booking Summary Receipt,',
    'Customer Invoice,',
    'Customer Receipt,',
    'cash_bank_audit_inventory.txt',
    'option_b_current_diff.txt',
    'treasury_module_audit.txt',
];

$foundUnexpected = array_values(array_filter(
    $unexpectedPaths,
    static fn (string $path): bool => file_exists(BASE_PATH . '/' . $path)
));

$warn(
    'Stray top-level review/export artifacts have been cleaned locally',
    $foundUnexpected === [],
    $foundUnexpected !== [] ? implode(', ', $foundUnexpected) : ''
);

$generatedPaths = [
    'storage/regression_screenshots',
    'storage/pw-temp',
];

$foundGenerated = array_values(array_filter(
    $generatedPaths,
    static fn (string $path): bool => file_exists(BASE_PATH . '/' . $path)
));

$warn(
    'Generated browser screenshot/temp directories have been cleaned locally',
    $foundGenerated === [],
    $foundGenerated !== [] ? implode(', ', $foundGenerated) : ''
);

if ($failures !== []) {
    echo PHP_EOL . 'Release hygiene audit failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Release hygiene audit passed.' . PHP_EOL;
