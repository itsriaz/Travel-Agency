<?php use App\Helpers\Auth; ?>
<div class="auth-center-wrapper">
    <section class="page-head auth-page-head">
        <div class="auth-icon-badge auth-icon-badge-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
        </div>
        <div>
            <h1>Backup Recovery Codes</h1>
            <p>Store these one-time recovery codes in a safe place. Each code can be used once to access your account if you lose your authenticator device.</p>
        </div>
    </section>

    <section class="panel compact-panel compact-auth-panel auth-card-panel">
        <div class="alert alert-warning recovery-codes-alert" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <span><strong>Important:</strong> These codes are displayed once only and cannot be retrieved later.</span>
        </div>

        <div class="code-grid recovery-code-grid">
            <?php foreach ($codes as $code): ?>
                <div class="recovery-code">
                    <code><?= e($code) ?></code>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions auth-actions top-gap">
            <a class="btn btn-primary auth-submit-btn" href="<?= e(url(Auth::isSuperAdmin() ? '/' : '/workspace')) ?>">
                <span>I Have Saved These Codes &mdash; Continue</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </a>
        </div>
    </section>
</div>
