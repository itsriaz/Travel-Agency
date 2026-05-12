<?php
$targetSummary = $target;
$targetUser = $targetSummary['user'] ?? null;
?>
<section class="page-head">
    <div>
        <h1>Super Admin Security Controls</h1>
        <p>Force resets and review targeted security activity without weakening core protections.</p>
    </div>
</section>

<section class="panel compact-panel">
    <form method="get" action="<?= e(url('/admin/security')) ?>" class="form-grid admin-select-form">
        <label class="field">
            <span>Select User</span>
            <select name="user_id">
                <option value="">Choose user</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e((string) $user['id']) ?>" <?= $selectedUserId === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['name'] . ' / ' . $user['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Load</button>
        </div>
    </form>
</section>

<?php if ($targetUser !== null): ?>
    <section class="workspace-grid security-grid">
        <div class="panel compact-panel">
            <div class="panel-header">
                <h2>Admin Actions</h2>
            </div>
            <form method="post" action="<?= e(url('/admin/security/force-password-reset')) ?>" class="form-grid compact-form">
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">
                <label class="field">
                    <span>Temporary Password</span>
                    <input type="text" name="temporary_password" required>
                </label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Force Password Reset</button>
                </div>
            </form>

            <form method="post" action="<?= e(url('/admin/security/reset-2fa')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">
                <button class="btn" type="submit">Reset 2FA</button>
            </form>

            <form method="post" action="<?= e(url('/admin/security/revoke-trusted-devices')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">
                <button class="btn btn-danger" type="submit">Revoke Trusted Devices</button>
            </form>
        </div>

        <div class="panel compact-panel">
            <div class="panel-header">
                <h2>Target Security Events</h2>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>IP</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($targetSummary['events'] ?? []) as $event): ?>
                            <tr>
                                <td><?= e($event['event_name']) ?></td>
                                <td><?= e((string) ($event['ip_address'] ?? '-')) ?></td>
                                <td><?= e((string) $event['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>
