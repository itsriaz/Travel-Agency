<?php
$lockState = is_array($lockState ?? null) ? $lockState : null;
$isLocked = $lockState !== null;
$loginValue = (string) ($loginValue ?? '');
?>
<section class="panel compact-panel">
    <div class="panel-header">
        <h1>Sign In</h1>
    </div>

    <form method="post" action="<?= e(url('/login')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <?php if ($isLocked): ?>
            <div class="alert alert-danger auth-lock-message"><?= e((string) ($lockState['message'] ?? 'Too many login attempts. Please wait and try again.')) ?></div>
        <?php endif; ?>
        <label class="field">
            <span>Username or Email</span>
            <input type="text" name="login" autocomplete="username" value="<?= e($loginValue) ?>" required<?= $isLocked ? ' disabled aria-disabled="true"' : ' autofocus' ?>>
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?>>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"<?= $isLocked ? ' disabled aria-disabled="true"' : '' ?>>Sign In</button>
            <a class="btn" href="<?= e(url('/forgot-password')) ?>">Forgot Password</a>
        </div>
    </form>
</section>
