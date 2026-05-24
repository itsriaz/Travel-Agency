<?php

use App\Helpers\Auth;
use App\Helpers\Flash;

$flash = Flash::consume();
$authUser = Auth::user();
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$isWorkspacePage = str_contains($requestPath, '/workspace');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(($title ?? 'Travel Agency Operations') . ' | Travel Agency Operations') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="app-shell<?= $isWorkspacePage ? ' workspace-page-shell' : '' ?>">
    <div class="topbar">
        <div class="brand-block">
            <div class="brand-title"><?= $isWorkspacePage ? 'AsiaSoft Travel' : 'Travel Agency Ops' ?></div>
            <div class="brand-subtitle"><?= $isWorkspacePage ? 'Branch: Imdad International Travel Agency, Swat' : 'Imdad International Travel Agency / Noble Route' ?></div>
        </div>
        <div class="topbar-actions">
            <a class="btn btn-sm" href="<?= e(url('/')) ?>">Dashboard</a>
            <a class="btn btn-sm" href="<?= e(url('/workspace')) ?>">Booking Workspace</a>
            <?php if ($authUser !== null): ?>
                <div class="user-chip"><?= e($authUser['name'] ?? '') ?> / <?= e($authUser['roleCode'] ?? $authUser['role_code'] ?? '') ?></div>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= \App\Helpers\Csrf::input() ?>
                    <button class="btn btn-sm btn-danger" type="submit">Sign Out</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-grid">
        <aside class="sidebar">
            <div class="nav-section">
                <div class="nav-label">Workspace</div>
                <a class="nav-link" href="<?= e(url('/workspace')) ?>">Search / New Booking</a>
                <a class="nav-link" href="<?= e(url('/reports')) ?>">Reports</a>
            </div>
            <div class="nav-section">
                <div class="nav-label">Control</div>
                <a class="nav-link" href="<?= e(url('/')) ?>">Dashboard</a>
                <a class="nav-link" href="<?= e(url('/security')) ?>">Security</a>
                <?php if (\App\Helpers\Auth::isSuperAdmin()): ?>
                    <a class="nav-link" href="<?= e(url('/admin/security')) ?>">Admin Security</a>
                    <a class="nav-link" href="<?= e(url('/master-data')) ?>">Master Data</a>
                    <a class="nav-link" href="<?= e(url('/accounting-engine')) ?>">Accounting Engine</a>
                    <a class="nav-link" href="<?= e(url('/treasury/accounts')) ?>">Treasury Accounts</a>
                <?php endif; ?>
            </div>
        </aside>

        <main class="content-panel">
            <?php if ($flash !== null): ?>
                <div class="alert alert-toast alert-<?= e($flash['type']) ?>" role="status" aria-live="polite"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
    <?php if (!empty($pageScript ?? null)): ?>
        <?php
        $pageScriptPath = base_path('/public/' . ltrim((string) $pageScript, '/'));
        $pageScriptVersion = is_file($pageScriptPath) ? (string) filemtime($pageScriptPath) : (string) time();
        ?>
        <script src="<?= e(asset((string) $pageScript) . '?v=' . rawurlencode($pageScriptVersion)) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
