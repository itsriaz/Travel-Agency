<section class="page-head">
    <div>
        <h1>Set Up Authenticator</h1>
        <p>Scan the QR code or enter the secret manually, then verify one code to enable 2FA.</p>
    </div>
</section>

<section class="panel compact-panel compact-auth-panel">
    <div class="two-factor-setup">
        <div class="qr-panel"><?= $qrSvg ?></div>
        <div class="form-grid">
            <div class="field-readonly">
                <span>Manual Secret</span>
                <code><?= e($manualSecret) ?></code>
            </div>
            <form method="post" action="<?= e(url('/2fa/setup')) ?>" class="form-grid compact-form">
                <?= \App\Helpers\Csrf::input() ?>
                <label class="field">
                    <span>Authenticator Code</span>
                    <input type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                </label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Enable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</section>
