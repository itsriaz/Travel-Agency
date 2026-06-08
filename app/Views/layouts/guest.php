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
    <main class="guest-card">
        <div class="guest-brand">Travel Agency Operations</div>
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</body>
</html>
