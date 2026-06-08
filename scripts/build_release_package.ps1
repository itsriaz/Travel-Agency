param(
    [string] $ReleaseName = "",
    [ValidateSet("full", "web")]
    [string] $Profile = "full"
)

$ErrorActionPreference = "Stop"

$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
if ([string]::IsNullOrWhiteSpace($ReleaseName)) {
    $suffix = if ($Profile -eq "web") { "web-production" } else { "release" }
    $ReleaseName = "travel-agency-$suffix-$Timestamp"
}

function Copy-TrackedPath {
    param(
        [string] $RelativePath
    )

    $source = Join-Path $Root $RelativePath
    if (-not (Test-Path $source)) {
        return
    }

    $destination = Join-Path $PackageDir $RelativePath
    $destinationParent = Split-Path -Parent $destination
    if (-not (Test-Path $destinationParent)) {
        New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
    }

    Copy-Item -LiteralPath $source -Destination $destination -Force
}

function Copy-TrackedDirectory {
    param(
        [string] $RelativeDirectory
    )

    $source = Join-Path $Root $RelativeDirectory
    if (-not (Test-Path $source)) {
        return
    }

    $gitDir = Join-Path $Root ".git"
    $canUseGit = (Test-Path $gitDir) -and (Get-Command git -ErrorAction SilentlyContinue)
    if ($canUseGit) {
        $files = & git -C $Root ls-files --cached --others --exclude-standard -- $RelativeDirectory
        if ($LASTEXITCODE -ne 0) {
            throw "git ls-files failed for $RelativeDirectory"
        }

        foreach ($file in $files) {
            if ([string]::IsNullOrWhiteSpace($file)) {
                continue
            }

            Copy-TrackedPath -RelativePath $file
        }

        return
    }

    Copy-Item -LiteralPath $source -Destination (Join-Path $PackageDir $RelativeDirectory) -Recurse -Force
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

$Directories = if ($Profile -eq "web") {
    @(
        "app",
        "config",
        "public",
        "routes"
    )
} else {
    @(
        "app",
        "config",
        "database",
        "docs",
        "public",
        "routes",
        "scripts",
        "tests"
    )
}

foreach ($Directory in $Directories) {
    Copy-TrackedDirectory -RelativeDirectory $Directory
}

$Files = if ($Profile -eq "web") {
    @(
        ".htaccess",
        "index.php"
    )
} else {
    @(
        ".env.production.example",
        ".htaccess",
        ".gitignore",
        "composer.json",
        "index.php",
        "migrate.php",
        "seed.php"
    )
}

foreach ($File in $Files) {
    Copy-TrackedPath -RelativePath $File
}

$StorageDir = Join-Path $PackageDir "storage"
New-Item -ItemType Directory -Path (Join-Path $StorageDir "logs") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StorageDir "documents") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StorageDir "runtime") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StorageDir "keys") -Force | Out-Null
Set-Content -Path (Join-Path $StorageDir ".gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "logs\.gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "documents\.gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "runtime\.gitkeep") -Value "" -NoNewline
Set-Content -Path (Join-Path $StorageDir "keys\.gitkeep") -Value "" -NoNewline

$LauncherGatePublicKey = Join-Path $Root "storage\keys\launcher_gate_public.pem"
if (Test-Path $LauncherGatePublicKey) {
    Copy-Item -LiteralPath $LauncherGatePublicKey -Destination (Join-Path $StorageDir "keys\launcher_gate_public.pem") -Force
}

$LauncherSource = Join-Path $Root "launcher"
if ($Profile -ne "web" -and (Test-Path $LauncherSource)) {
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
        Copy-TrackedPath -RelativePath ("launcher/" + $File)
    }
}

if ($Profile -eq "web") {
    $ReadmePath = Join-Path $PackageDir "DEPLOY_README.txt"
    @(
        "Lean production web package",
        "",
        "Included:",
        "- app",
        "- config",
        "- public",
        "- routes",
        "- storage (empty runtime/log/document dirs + launcher public key)",
        "- index.php",
        "- .htaccess",
        "",
        "Not included:",
        "- database migration/seed tooling",
        "- docs",
        "- tests",
        "- launcher source",
        "- scripts",
        "- .env example",
        "",
        "Create your real .env separately on the server.",
        "Do not upload the launcher private key to hosting."
    ) | Set-Content -Path $ReadmePath
}

Compress-Archive -Path (Join-Path $PackageDir "*") -DestinationPath $ZipPath -Force

Write-Host "Release folder: $PackageDir"
Write-Host "Release zip: $ZipPath"
