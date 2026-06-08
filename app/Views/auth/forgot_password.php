<section class="panel compact-panel">
    <div class="panel-header">
        <h1>Forgot Password</h1>
        <div class="panel-meta">Enter your registered email address</div>
    </div>

    <form method="post" action="<?= e(url('/forgot-password')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field">
            <span>Username or Email</span>
            <input type="text" name="login" autocomplete="username" required autofocus>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Request Reset</button>
            <a class="btn" href="<?= e(url('/login')) ?>">Back to Sign In</a>
        </div>
    </form>
</section>
