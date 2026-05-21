<?php
$targetSummary = $target;
$targetUser = $targetSummary['user'] ?? null;
$roles = $roleBranchOptions['roles'] ?? [];
$branches = $roleBranchOptions['branches'] ?? [];
$branchAccessIds = array_map('intval', $targetSummary['branch_access_ids'] ?? []);
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
                        <?= e($user['name'] . ' / ' . $user['username'] . ' / ' . $user['role_code'] . ' / ' . $user['default_branch_name']) ?>
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
    <section class="panel compact-panel">
        <div class="panel-header">
            <h2>Role And Branch Access</h2>
        </div>
        <form method="post" action="<?= e(url('/admin/security/role-branch-access')) ?>" class="form-grid compact-form">
            <?= \App\Helpers\Csrf::input() ?>
            <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">

            <label class="field">
                <span>Role</span>
                <select name="role_code" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e((string) $role['code']) ?>" <?= (string) ($targetUser['role_code'] ?? '') === (string) $role['code'] ? 'selected' : '' ?>>
                            <?= e((string) $role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Default Branch</span>
                <select name="default_branch_id" required>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= e((string) $branch['id']) ?>" <?= (int) ($targetUser['default_branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e((string) $branch['name'] . ' / ' . (string) $branch['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <fieldset class="field span-2">
                <span>Accessible Branches</span>
                <div class="checkbox-grid">
                    <?php foreach ($branches as $branch): ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="branch_ids[]" value="<?= e((string) $branch['id']) ?>" <?= in_array((int) $branch['id'], $branchAccessIds, true) ? 'checked' : '' ?>>
                            <span><?= e((string) $branch['name'] . ' / ' . (string) $branch['code']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save Role Access</button>
            </div>
        </form>
    </section>

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
