<?php use App\Helpers\Auth; ?>
<section class="page-head">
    <div>
        <h1>Backup Recovery Codes</h1>
        <p>These codes are shown once only. Each code can be used a single time.</p>
    </div>
</section>

<section class="panel compact-panel compact-auth-panel">
    <div class="code-grid">
        <?php foreach ($codes as $code): ?>
            <div class="recovery-code"><?= e($code) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="form-actions top-gap">
        <a class="btn btn-primary" href="<?= e(url(Auth::isSuperAdmin() ? '/' : '/workspace')) ?>">Continue</a>
    </div>
</section>
