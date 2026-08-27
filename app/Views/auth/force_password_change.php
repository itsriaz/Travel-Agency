<div class="auth-center-wrapper">
    <section class="page-head auth-page-head">
        <div class="auth-icon-badge">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <div>
            <h1>Security Update Required</h1>
            <p>You must set a new password before accessing operations.</p>
        </div>
    </section>

    <section class="panel compact-panel compact-auth-panel auth-card-panel">
        <form method="post" action="<?= e(url('/force-password-change')) ?>" class="form-grid compact-form auth-form">
            <?= \App\Helpers\Csrf::input() ?>
            <label class="field auth-field">
                <span class="field-label">Current Password</span>
                <input type="password" name="current_password" autocomplete="current-password" required autofocus placeholder="Enter current password">
            </label>
            <label class="field auth-field">
                <span class="field-label">New Password</span>
                <input type="password" name="password" autocomplete="new-password" required placeholder="Enter new password (min 12 characters)">
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
                <span>Password must be at least 12 characters and can be a passphrase.</span>
            </div>
            <div class="form-actions auth-actions">
                <button class="btn btn-primary auth-submit-btn" type="submit">
                    <span>Update Password</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </button>
            </div>
        </form>
        <div class="auth-divider">
            <span>or</span>
        </div>
        <form method="post" action="<?= e(url('/logout')) ?>" class="compact-inline-form auth-signout-form">
            <?= \App\Helpers\Csrf::input() ?>
            <button class="btn btn-outline-danger" type="submit">Sign Out of Session</button>
        </form>
    </section>
</div>
