<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Server Error') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="guest-shell">
    <main class="guest-card">
        <div class="guest-brand">500</div>
        <div class="guest-subtitle">An unexpected error occurred.</div>
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">Go Home</a>
    </main>
</body>
</html>
