<?php

use App\Helpers\Flash;

$flash = Flash::consume();
$appCssPath = base_path('public/assets/css/app.css');
$appCssVersion = is_file($appCssPath) ? (string) filemtime($appCssPath) : '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(($title ?? 'Sign In') . ' | Travel Agency Operations') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css') . '?v=' . rawurlencode($appCssVersion)) ?>">
</head>
<body class="guest-shell">
    <div class="guest-container">
        <header class="guest-header">
            <div class="guest-logo-badge">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="2" y1="12" x2="22" y2="12"></line>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                </svg>
            </div>
            <div class="guest-brand">Travel Agency Operations</div>
            <div class="guest-subtitle">Enterprise Operations &amp; Management Platform</div>
        </header>

        <main class="guest-card">
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>

        <footer class="guest-footer">
            <div class="guest-security-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <span>Encrypted Operations Gateway &bull; Authorized Personnel Only</span>
            </div>
        </footer>
    </div>
</body>
</html>
