<section class="panel compact-panel">
    <div class="panel-header">
        <h1>Sign In</h1>
        <div class="panel-meta">Compact secure access for super admin and branch employees.</div>
    </div>

    <form method="post" action="<?= e(url('/login')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field">
            <span>Username or Email</span>
            <input type="text" name="login" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <div class="login-hint">Use `admin` or `admin@travelagency.local`, or `employee` or `employee@travelagency.local`.</div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Sign In</button>
            <a class="btn" href="<?= e(url('/forgot-password')) ?>">Forgot Password</a>
        </div>
    </form>
</section>
