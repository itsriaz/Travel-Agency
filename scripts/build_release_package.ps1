param(
    [string] $ReleaseName = ""
)

$ErrorActionPreference = "Stop"

$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
if ([string]::IsNullOrWhiteSpace($ReleaseName)) {
    $ReleaseName = "travel-agency-release-$Timestamp"
}

$OutputRoot = Join-Path $Root "storage\release-packages"
$PackageDir = Join-Path $OutputRoot $ReleaseName
$ZipPath = Join-Path $OutputRoot "$ReleaseName.zip"

if (-not (Test-Path $OutputRoot)) {
    New-Item -ItemType Directory -Path $OutputRoot | Out-Null
}

if (Test-Path $PackageDir) {
    Remove-Item -LiteralPath $PackageDir -Recurse -Force
}

if (Test-Path $ZipPath) {
    Remove-Item -LiteralPath $ZipPath -Force
}

New-Item -ItemType Directory -Path $PackageDir | Out-Null

$Directories = @(
    "app",
    "config",
    "database",
    "docs",
    "public",
    "routes",
    "scripts",
    "tests"
)

foreach ($Directory in $Directories) {
    $Source = Join-Path $Root $Directory
    if (Test-Path $Source) {
        Copy-Item -LiteralPath $Source -Destination (Join-Path $PackageDir $Directory) -Recurse -Force
    }
}

$Files = @(
    ".env.production.example",
    ".htaccess",
    ".gitignore",
    "composer.json",
    "index.php",
    "migrate.php",
    "seed.php"
)

foreach ($File in $Files) {
    $Source = Join-Path $Root $File
    if (Test-Path $Source) {
        Copy-Item -LiteralPath $Source -Destination (Join-Path $PackageDir $File) -Force
    }
}

$StorageDir = Join-Path $PackageDir "storage"
New-Item -ItemType Directory -Path (Join-Path $StorageDir "logs") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StorageDir "documents") -Force | Out-Null
Set-Content -Path (Join-Path $StorageDir ".gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "logs\.gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "documents\.gitkeep") -Value "" -NoNewline

$LauncherSource = Join-Path $Root "launcher"
if (Test-Path $LauncherSource) {
    $LauncherDestination = Join-Path $PackageDir "launcher"
    New-Item -ItemType Directory -Path $LauncherDestination | Out-Null
    foreach ($File in @(
        "launcher-config.example.json",
        "launcher-config.production.example.json",
        "main.js",
        "offline.html",
        "package-lock.json",
        "package.json",
        "preload.js",
        "README.md",
        "renderer.js",
        "styles.css"
    )) {
        $Source = Join-Path $LauncherSource $File
        if (Test-Path $Source) {
            Copy-Item -LiteralPath $Source -Destination (Join-Path $LauncherDestination $File) -Force
        }
    }
}

Compress-Archive -Path (Join-Path $PackageDir "*") -DestinationPath $ZipPath -Force

Write-Host "Release folder: $PackageDir"
Write-Host "Release zip: $ZipPath"
