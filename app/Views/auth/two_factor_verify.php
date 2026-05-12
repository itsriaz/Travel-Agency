<section class="panel compact-panel compact-auth-panel">
    <div class="panel-header">
        <h1>Verify 2FA</h1>
        <div class="panel-meta">Enter a 6-digit authenticator code or one backup recovery code.</div>
    </div>

    <form method="post" action="<?= e(url('/2fa/verify')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field">
            <span>Authenticator or Recovery Code</span>
            <input type="text" name="code" autocomplete="one-time-code" required autofocus>
        </label>
        <?php if (!\App\Helpers\Auth::isSuperAdmin()): ?>
            <label class="check-field">
                <input type="checkbox" name="remember_device" value="1">
                <span>Remember this device for up to 7 days</span>
            </label>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Verify</button>
        </div>
    </form>
</section>
