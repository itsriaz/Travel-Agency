<section class="panel compact-panel auth-card-panel">
    <div class="panel-header auth-panel-header">
        <div class="auth-header-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
            </svg>
        </div>
        <div>
            <h1>Reset Password</h1>
            <div class="panel-meta">Create a new secure password for your operator account</div>
        </div>
    </div>

    <form method="post" action="<?= e(url('/reset-password')) ?>" class="form-grid compact-form auth-form">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="selector" value="<?= e($selector ?? '') ?>">
        <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
        <label class="field auth-field">
            <span class="field-label">New Password</span>
            <input type="password" name="password" autocomplete="new-password" required placeholder="Enter new password" autofocus>
        </label>
        <label class="field auth-field">
            <span class="field-label">Confirm Password</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required placeholder="Re-enter new password">
        </label>
        <div class="login-hint auth-security-note">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <span>Password must be at least 12 characters. A passphrase is recommended.</span>
        </div>
        <div class="form-actions auth-actions">
            <button class="btn btn-primary auth-submit-btn" type="submit">
                <span>Save &amp; Reset Password</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </button>
            <a class="btn btn-secondary-subtle" href="<?= e(url('/login')) ?>">
                &larr; Back to Sign In
            </a>
        </div>
    </form>
</section>
