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
    </div>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <h2>Create User</h2>
    </div>
    <form method="post" action="<?= e(url('/admin/security/users/create')) ?>" class="form-grid compact-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="field">
            <span>Name</span>
            <input type="text" name="name" required>
        </label>
        <label class="field">
            <span>Username</span>
            <input type="text" name="username" required>
        </label>
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" required>
        </label>
        <label class="field">
            <span>Temporary Password</span>
            <input type="text" name="temporary_password" required>
        </label>
        <label class="field">
            <span>Role</span>
            <select name="role_code" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e((string) $role['code']) ?>" <?= (string) $role['code'] === 'super_admin' ? 'selected' : '' ?>>
                        <?= e((string) $role['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Default Branch</span>
            <select name="default_branch_id" required>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>"><?= e((string) $branch['name'] . ' / ' . (string) $branch['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset class="field span-2">
            <span>Accessible Branches</span>
            <div class="checkbox-grid">
                <?php foreach ($branches as $branch): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="branch_ids[]" value="<?= e((string) $branch['id']) ?>" checked>
                        <span><?= e((string) $branch['name'] . ' / ' . (string) $branch['code']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Create User</button>
        </div>
    </form>
</section>

<section class="panel compact-panel">
    <form method="get" action="<?= e(url('/admin/security')) ?>" class="form-grid admin-select-form" data-security-admin-picker-form>
        <label class="field">
            <span>Select User</span>
            <select name="user_id" data-security-admin-picker>
                <option value="">Choose user</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e((string) $user['id']) ?>" <?= $selectedUserId === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['username'] . ' / ' . $user['role_code'] . ' / ' . $user['default_branch_name'] . ' / ' . (((int) ($user['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive')) ?>
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
    <section class="panel compact-panel" id="loaded-user-record" data-security-admin-loaded-record tabindex="-1">
        <div class="panel-header">
            <h2>Edit User Details</h2>
        </div>
        <form method="post" action="<?= e(url('/admin/security/users/update')) ?>" class="form-grid compact-form">
            <?= \App\Helpers\Csrf::input() ?>
            <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">

            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="<?= e((string) ($targetUser['name'] ?? '')) ?>" required>
            </label>

            <label class="field">
                <span>Username</span>
                <input type="text" name="username" value="<?= e((string) ($targetUser['username'] ?? '')) ?>" required>
            </label>

            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="<?= e((string) ($targetUser['email'] ?? '')) ?>" required>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save User Details</button>
            </div>
        </form>
    </section>

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

    <section class="panel compact-panel security-admin-actions-panel">
        <div class="panel-header">
            <h2>Admin Actions</h2>
        </div>
        <div class="security-admin-action-row">
            <form method="post" action="<?= e(url('/admin/security/force-password-reset')) ?>" class="security-admin-password-form">
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">
                <label class="field">
                    <span>Temporary Password</span>
                    <input type="text" name="temporary_password" required>
                </label>
                <button class="btn btn-primary" type="submit">Reset Password</button>
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

            <form method="post" action="<?= e(url('/admin/security/users/status')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="target_user_id" value="<?= e((string) $targetUser['id']) ?>">
                <input type="hidden" name="is_active" value="<?= (int) ($targetUser['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                <button class="btn <?= (int) ($targetUser['is_active'] ?? 0) === 1 ? 'btn-danger' : 'btn-primary' ?>" type="submit">
                    <?= (int) ($targetUser['is_active'] ?? 0) === 1 ? 'Deactivate User' : 'Reactivate User' ?>
                </button>
            </form>
        </div>
    </section>

    <section class="panel compact-panel security-admin-events-panel">
        <div class="panel-header">
            <h2>Target Security Events</h2>
        </div>
        <div class="dense-table-wrap">
            <table class="dense-table security-admin-events-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>IP</th>
                        <th>Device</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($targetSummary['events'] ?? []) as $event): ?>
                        <tr>
                            <td><?= e($event['event_name']) ?></td>
                            <td><?= e((string) ($event['ip_address'] ?? '-')) ?></td>
                            <td title="<?= e((string) ($event['user_agent'] ?? '')) ?>">
                                <?= e((string) (($event['device_label'] ?? '') !== '' ? $event['device_label'] : ($event['user_agent_summary'] ?? '-'))) ?>
                            </td>
                            <td><?= e((string) $event['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const pickerForm = document.querySelector('[data-security-admin-picker-form]');
    const pickerSelect = document.querySelector('[data-security-admin-picker]');
    const loadedRecord = document.querySelector('[data-security-admin-loaded-record]');

    if (pickerForm instanceof HTMLFormElement && pickerSelect instanceof HTMLSelectElement) {
        pickerSelect.addEventListener('change', () => {
            if (!pickerSelect.value) {
                return;
            }

            const actionUrl = new URL(pickerForm.action, window.location.origin);
            actionUrl.hash = 'loaded-user-record';
            pickerForm.action = actionUrl.toString();
            pickerForm.submit();
        });
    }

    if (loadedRecord instanceof HTMLElement && window.location.hash === '#loaded-user-record') {
        window.requestAnimationFrame(() => {
            loadedRecord.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
            loadedRecord.focus({ preventScroll: true });
        });
    }
});
</script>
