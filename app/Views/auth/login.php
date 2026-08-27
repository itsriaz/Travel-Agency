<?php
$lockState = is_array($lockState ?? null) ? $lockState : null;
$isLocked = $lockState !== null;
$loginValue = (string) ($loginValue ?? '');
?>
<section class="panel compact-panel auth-card-panel">
    <div class="panel-header auth-panel-header">
        <div class="auth-header-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                <polyline points="10 17 15 12 10 7"></polyline>
                <line x1="15" y1="12" x2="3" y2="12"></line>
            </svg>
        </div>
        <div>
            <h1>Sign In</h1>
            <div class="panel-meta">Enter your operator credentials to access the system</div>
        </div>
    </div>

    <form method="post" action="<?= e(url('/login')) ?>" class="form-grid compact-form auth-form">
        <?= \App\Helpers\Csrf::input() ?>
        <?php if ($isLocked): ?>
            <div class="alert alert-danger auth-lock-message" role="alert"><?= e((string) ($lockState['message'] ?? 'Too many login attempts. Please wait and try again.')) ?></div>
        <?php endif; ?>
        <label class="field auth-field">
            <span class="field-label">Username or Email</span>
            <div class="input-with-icon">
                <input type="text" name="login" autocomplete="username" value="<?= e($loginValue) ?>" required<?= $isLocked ? ' disabled aria-disabled="true"' : ' autofocus' ?> placeholder="Enter username or email">
            </div>
        </label>
        <label class="field auth-field">
            <div class="field-label-row">
                <span class="field-label">Password</span>
            </div>
            <div class="input-with-icon">
                <input type="password" name="password" autocomplete="current-password" required<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?> placeholder="Enter password">
            </div>
        </label>
        <div class="form-actions auth-actions">
            <button class="btn btn-primary auth-submit-btn" type="submit"<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?>>
                <span>Sign In</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </button>
            <a class="btn btn-secondary-subtle" href="<?= e(url('/forgot-password')) ?>">Forgot Password?</a>
        </div>
    </form>
</section>
