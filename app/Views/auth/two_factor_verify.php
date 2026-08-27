<?php
$lockState = is_array($lockState ?? null) ? $lockState : null;
$isLocked = $lockState !== null;
?>
<div class="auth-center-wrapper">
    <section class="panel compact-panel compact-auth-panel auth-card-panel">
        <div class="panel-header auth-panel-header">
            <div class="auth-header-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <polyline points="9 12 11 14 15 10"></polyline>
                </svg>
            </div>
            <div>
                <h1>Two-Factor Verification</h1>
                <div class="panel-meta">Enter your 6-digit authenticator code or one backup recovery code</div>
            </div>
        </div>

        <form method="post" action="<?= e(url('/2fa/verify')) ?>" class="form-grid compact-form auth-form">
            <?= \App\Helpers\Csrf::input() ?>
            <?php if ($isLocked): ?>
                <div class="alert alert-danger auth-lock-message" role="alert"><?= e((string) ($lockState['message'] ?? 'Too many OTP failures. Please wait and try again.')) ?></div>
            <?php endif; ?>
            <label class="field auth-field">
                <span class="field-label">Authenticator or Recovery Code</span>
                <input type="text" name="code" autocomplete="one-time-code" required<?= $isLocked ? ' disabled aria-disabled="true"' : ' autofocus' ?> placeholder="Enter 6-digit OTP or backup code" class="otp-input">
            </label>
            <?php if (!\App\Helpers\Auth::isSuperAdmin()): ?>
                <label class="check-field auth-check-field">
                    <input type="checkbox" name="remember_device" value="1"<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?>>
                    <span>Remember this device for up to 7 days</span>
                </label>
            <?php endif; ?>
            <div class="form-actions auth-actions">
                <button class="btn btn-primary auth-submit-btn" type="submit"<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?>>
                    <span>Verify &amp; Enter Dashboard</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </div>
        </form>
    </section>
</div>
