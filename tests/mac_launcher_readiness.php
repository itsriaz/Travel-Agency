<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$launcherRoot = BASE_PATH . '/launcher';
$configPath = $launcherRoot . '/electron-builder.mac.yml';
$buildScriptPath = $launcherRoot . '/scripts/build_mac.sh';
$iconScriptPath = $launcherRoot . '/scripts/prepare_mac_icon.sh';
$sourceIconPath = $launcherRoot . '/assets/app-icon-mac-1024.png';
$macConfigPath = $launcherRoot . '/launcher-config.mac.json';
$windowsPackagePath = $launcherRoot . '/package.json';
$mainPath = $launcherRoot . '/main.js';
$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';
$macBuilder = $read($configPath);
$buildScript = $read($buildScriptPath);
$iconScript = $read($iconScriptPath);
$macConfig = json_decode($read($macConfigPath), true);
$windowsPackage = json_decode($read($windowsPackagePath), true);
$main = $read($mainPath);

$check('Separate macOS Electron Builder configuration exists', $macBuilder !== '');
$check(
    'macOS build targets a Universal DMG',
    str_contains($macBuilder, 'target: dmg')
        && str_contains($macBuilder, '- universal')
        && str_contains($macBuilder, 'output: dist-mac')
);
$check(
    'macOS package embeds its own production launcher configuration',
    str_contains($macBuilder, 'from: launcher-config.mac.json')
        && str_contains($macBuilder, 'to: launcher-config.json')
        && is_array($macConfig)
        && ($macConfig['serverUrl'] ?? '') === 'https://app.imdadint.ae'
        && ($macConfig['sourceDevice'] ?? '') === 'Branch macOS Launcher'
);
$check(
    'macOS icon preparation uses native Mac icon tools and a high-resolution source',
    is_file($sourceIconPath)
        && str_contains($iconScript, 'sips -z')
        && str_contains($iconScript, 'iconutil -c icns')
);
$check(
    'macOS build script refuses non-Mac hosts and invokes only the Mac configuration',
    str_contains($buildScript, '"$(uname -s)" != "Darwin"')
        && str_contains($buildScript, '--config electron-builder.mac.yml --mac --universal')
);
$check(
    'Existing Windows packaging commands and targets remain intact',
    is_array($windowsPackage)
        && ($windowsPackage['scripts']['package:win'] ?? '') === 'electron-builder --win portable'
        && ($windowsPackage['scripts']['package:win:installer'] ?? '') === 'electron-builder --win nsis'
        && ($windowsPackage['build']['win']['target'] ?? '') === 'portable'
);
$check(
    'Shared launcher already follows the macOS activate/window lifecycle',
    str_contains($main, "process.platform !== 'darwin'")
        && str_contains($main, "app.on('activate'")
);

if ($failures !== []) {
    echo PHP_EOL . 'macOS launcher readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'macOS launcher readiness passed.' . PHP_EOL;
