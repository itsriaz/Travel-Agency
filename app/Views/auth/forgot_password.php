<section class="panel compact-panel auth-card-panel">
    <div class="panel-header auth-panel-header">
        <div class="auth-header-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <div>
            <h1>Forgot Password</h1>
            <div class="panel-meta">Enter your registered username or email to receive reset instructions</div>
        </div>
    </div>

    <form method="post" action="<?= e(url('/forgot-password')) ?>" class="form-grid compact-form auth-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field auth-field">
            <span class="field-label">Username or Email</span>
            <input type="text" name="login" autocomplete="username" required autofocus placeholder="e.g. operator@company.com">
        </label>
        <div class="form-actions auth-actions">
            <button class="btn btn-primary auth-submit-btn" type="submit">
                <span>Request Reset</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
            <a class="btn btn-secondary-subtle" href="<?= e(url('/login')) ?>">
                &larr; Back to Sign In
            </a>
        </div>
    </form>
</section>
