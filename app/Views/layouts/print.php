<?php
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(($title ?? 'Operational Output') . ' | Travel Agency Operations') ?></title>
    <?php
    $printCssPath = base_path('public/assets/css/app.css');
    $printCssVersion = is_file($printCssPath) ? (string) filemtime($printCssPath) : '1';
    ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css?v=' . $printCssVersion)) ?>">
</head>
<body class="print-shell">
    <?= $content ?>
</body>
</html>
