<?php

use App\Helpers\Auth;
use App\Helpers\Flash;

$flash = Flash::consume();
$authUser = Auth::user();
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$isWorkspacePage = str_contains($requestPath, '/workspace');
$shellClass = $isWorkspacePage ? ' workspace-page-shell' : ' admin-page-shell';
$activeBranchName = trim((string) ($authUser['activeBranchName'] ?? $authUser['active_branch_name'] ?? ''));
$defaultBranchName = trim((string) ($authUser['defaultBranchName'] ?? $authUser['default_branch_name'] ?? ''));
$accessibleBranchNames = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    (array) ($authUser['accessibleBranchNames'] ?? $authUser['accessible_branch_names'] ?? [])
)));
$branchSubtitle = $activeBranchName !== ''
    ? 'Branch: ' . $activeBranchName
    : ($defaultBranchName !== '' ? 'Branch: ' . $defaultBranchName : '');
$adminSubtitle = $accessibleBranchNames !== []
    ? implode(' / ', $accessibleBranchNames)
    : ($branchSubtitle !== '' ? $branchSubtitle : 'Travel Agency Operations');
$isCurrentPath = static function (string $path) use ($requestPath): bool {
    if ($path === '/') {
        return $requestPath === '/';
    }

    return $requestPath === $path || str_starts_with($requestPath, $path . '/');
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(($title ?? 'Travel Agency Operations') . ' | Travel Agency Operations') ?></title>
    <?php
    $appCssPath = base_path('/public/assets/css/app.css');
    $appCssVersion = is_file($appCssPath) ? (string) filemtime($appCssPath) : (string) time();
    ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css') . '?v=' . rawurlencode($appCssVersion)) ?>">
</head>
<body class="app-shell<?= $shellClass ?>">
    <div class="topbar">
        <div class="brand-block">
            <div class="brand-title">Travel Agency Ops</div>
            <div class="brand-subtitle"><?= e($isWorkspacePage ? $branchSubtitle : $adminSubtitle) ?></div>
        </div>
        <div class="topbar-actions">
            <?php if ($authUser !== null): ?>
                <div class="history-nav" data-history-nav>
                    <button class="btn btn-sm history-nav__btn" type="button" data-history-back title="Go back to the previous screen">Back</button>
                    <button class="btn btn-sm history-nav__btn" type="button" data-history-forward title="Go forward to the next screen">Forward</button>
                </div>
            <?php endif; ?>
            <a class="btn btn-sm<?= $isCurrentPath('/') ? ' is-current' : '' ?>" href="<?= e(url('/')) ?>">Dashboard</a>
            <a class="btn btn-sm<?= $isCurrentPath('/workspace') ? ' is-current' : '' ?>" href="<?= e(url('/workspace')) ?>">Booking Workspace</a>
            <?php if ($authUser !== null): ?>
                <div class="user-chip"><?= e($authUser['username'] ?? $authUser['email'] ?? '') ?> / <?= e($authUser['roleCode'] ?? $authUser['role_code'] ?? '') ?></div>
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
                <a class="nav-link<?= $isCurrentPath('/workspace') ? ' is-current' : '' ?>" href="<?= e(url('/workspace')) ?>">Search / New Booking</a>
                <a class="nav-link<?= $isCurrentPath('/reports') || $isCurrentPath('/suppliers/settlements') ? ' is-current' : '' ?>" href="<?= e(url('/reports')) ?>">Reports</a>
            </div>
            <div class="nav-section">
                <div class="nav-label">Control</div>
                <a class="nav-link<?= $isCurrentPath('/') ? ' is-current' : '' ?>" href="<?= e(url('/')) ?>">Dashboard</a>
                <a class="nav-link<?= $isCurrentPath('/security') ? ' is-current' : '' ?>" href="<?= e(url('/security')) ?>">Security</a>
                <?php if (\App\Helpers\Auth::isSuperAdmin()): ?>
                    <a class="nav-link<?= $isCurrentPath('/admin/security') ? ' is-current' : '' ?>" href="<?= e(url('/admin/security')) ?>">Admin Security</a>
                    <div class="nav-link-group">
                        <a class="nav-link<?= $isCurrentPath('/master-data') ? ' is-current' : '' ?>" href="<?= e(url('/master-data')) ?>">Master Data</a>
                        <div class="nav-submenu" aria-label="Master data quick links">
                            <a href="<?= e(url('/master-data/suppliers')) ?>">Manage Suppliers</a>
                        </div>
                    </div>
                    <a class="nav-link" href="<?= e(url('/suppliers/settlements/global?start_new_payment=1&add_supplier=1')) ?>">Add Supplier</a>
                    <a class="nav-link<?= $isCurrentPath('/linked-party-settlements') ? ' is-current' : '' ?>" href="<?= e(url('/linked-party-settlements')) ?>">Account and Supplier Links</a>
                    <div class="nav-link-group">
                        <a class="nav-link<?= $isCurrentPath('/treasury/accounts') ? ' is-current' : '' ?>" href="<?= e(url('/treasury/accounts')) ?>">Treasury Accounts</a>
                        <div class="nav-submenu" aria-label="Treasury quick links">
                            <a href="<?= e(url('/treasury/accounts#treasury-account-setup')) ?>">Add Treasury Account</a>
                            <a href="<?= e(url('/treasury/accounts#treasury-direct-entry')) ?>">Money In / Money Out</a>
                            <a href="<?= e(url('/treasury/accounts#treasury-transfer')) ?>">Internal Treasury Transfer</a>
                            <a href="<?= e(url('/treasury/accounts#treasury-account-register')) ?>">Treasury Accounts</a>
                            <a href="<?= e(url('/treasury/accounts#treasury-recent-activity')) ?>">Recent Treasury Activity</a>
                        </div>
                    </div>
                    <a class="nav-link<?= $isCurrentPath('/expenses') ? ' is-current' : '' ?>" href="<?= e(url('/expenses')) ?>">Business Expenses</a>
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
    <?php if ($authUser !== null): ?>
        <script>
            (function () {
                var backButton = document.querySelector('[data-history-back]');
                var forwardButton = document.querySelector('[data-history-forward]');

                if (!backButton || !forwardButton) {
                    return;
                }

                if ('scrollRestoration' in window.history) {
                    window.history.scrollRestoration = 'auto';
                }

                var updateButtons = function () {
                    var canGoBack = window.history.length > 1 || document.referrer !== '';
                    backButton.disabled = !canGoBack;
                };

                backButton.addEventListener('click', function () {
                    if (window.history.length > 1 || document.referrer !== '') {
                        window.history.back();
                    }
                });

                forwardButton.addEventListener('click', function () {
                    window.history.forward();
                });

                window.addEventListener('pageshow', updateButtons);
                window.addEventListener('popstate', updateButtons);
                updateButtons();
            }());
        </script>
    <?php endif; ?>
</body>
</html>
