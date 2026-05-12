<section class="page-head">
    <div>
        <h1>Change Password</h1>
        <p>You must change your password before accessing the system.</p>
    </div>
</section>

<section class="panel compact-panel compact-auth-panel">
    <form method="post" action="<?= e(url('/force-password-change')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field">
            <span>Current Password</span>
            <input type="password" name="current_password" autocomplete="current-password" required autofocus>
        </label>
        <label class="field">
            <span>New Password</span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label class="field">
            <span>Confirm Password</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>
        <div class="login-hint">Password must be at least 12 characters and can be a long passphrase.</div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update Password</button>
        </div>
    </form>
    <form method="post" action="<?= e(url('/logout')) ?>" class="compact-inline-form">
        <?= \App\Helpers\Csrf::input() ?>
        <button class="btn btn-danger" type="submit">Sign Out</button>
    </form>
</section>
