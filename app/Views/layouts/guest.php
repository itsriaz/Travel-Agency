<?php

use App\Helpers\Flash;

$flash = Flash::consume();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(($title ?? 'Sign In') . ' | Travel Agency Operations') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="guest-shell">
    <main class="guest-card">
        <div class="guest-brand">Travel Agency Operations</div>
        <div class="guest-subtitle">Foundation login shell for secure XAMPP-first setup</div>
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</body>
</html>
