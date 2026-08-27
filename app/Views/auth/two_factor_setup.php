<div class="auth-center-wrapper auth-center-wide">
    <section class="page-head auth-page-head">
        <div class="auth-icon-badge">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
        </div>
        <div>
            <h1>Two-Factor Authentication Setup</h1>
            <p>Scan the QR code with your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password), or use the manual secret key.</p>
        </div>
    </section>

    <section class="panel compact-panel compact-auth-panel auth-card-panel auth-card-wide">
        <div class="two-factor-setup">
            <div class="qr-panel-container">
                <?php if (trim((string) ($qrSvg ?? '')) !== ''): ?>
                    <div class="qr-panel"><?= $qrSvg ?></div>
                    <div class="qr-hint">Scan with Authenticator App</div>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        QR code is unavailable on this server. Use the manual secret below, or enable the PHP XMLWriter/libxml extension on the host to restore QR scanning.
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-grid setup-steps-grid">
                <div class="field-readonly">
                    <span class="field-label">Manual Secret Key</span>
                    <div class="code-box">
                        <code><?= e($manualSecret) ?></code>
                    </div>
                    <div class="code-note">Use this key if your authenticator cannot scan QR codes.</div>
                </div>
                <form method="post" action="<?= e(url('/2fa/setup')) ?>" class="form-grid compact-form auth-form">
                    <?= \App\Helpers\Csrf::input() ?>
                    <label class="field auth-field">
                        <span class="field-label">Verification Code (6-digit OTP)</span>
                        <input type="text" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="10" autocomplete="one-time-code" required autofocus placeholder="000000" class="otp-input">
                    </label>
                    <div class="form-actions auth-actions">
                        <button class="btn btn-primary auth-submit-btn" type="submit">
                            <span>Enable 2FA &amp; Continue</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
