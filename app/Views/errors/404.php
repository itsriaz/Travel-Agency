<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Not Found') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="guest-shell">
    <main class="guest-card">
        <div class="guest-brand">404</div>
        <div class="guest-subtitle">The requested page was not found.</div>
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">Go Home</a>
    </main>
</body>
</html>
