<?php
$targetSummary = $target;
$targetUser = $targetSummary['user'] ?? null;
$roles = $roleBranchOptions['roles'] ?? [];
$branches = $roleBranchOptions['branches'] ?? [];
$branchAccessIds = array_map('intval', $targetSummary['branch_access_ids'] ?? []);
$editMode = (bool) ($editMode ?? false);
?>
<style>
    .security-admin-head {
        position: relative;
        overflow: hidden;
        min-height: 84px;
        padding: 1rem 1.25rem 1rem 1.8rem;
        border: 1px solid #155a7d;
        border-radius: 18px;
        color: #fff;
        background:
            radial-gradient(circle at 88% 12%, rgba(76, 211, 222, 0.2), transparent 28%),
            linear-gradient(112deg, #123c5a 0%, #075d82 68%, #087a99 100%);
        box-shadow: 0 14px 32px rgba(16, 60, 88, 0.18);
    }

    .security-admin-head::before {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        left: 0;
        width: 6px;
        border-radius: 18px 0 0 18px;
        background: #51d2df;
        box-shadow: 0 0 18px rgba(81, 210, 223, 0.52);
    }

    .security-admin-head h1 {
        margin: 0;
        color: inherit;
        font-size: clamp(1.5rem, 2vw, 2rem);
        letter-spacing: -0.025em;
    }

    .security-admin-head .page-actions {
        position: relative;
        z-index: 1;
    }

    .security-admin-head .page-actions .btn {
        border-color: rgba(255, 255, 255, 0.58);
        color: #123d5c;
        background: #fff;
        box-shadow: 0 7px 18px rgba(5, 32, 51, 0.18);
    }

    .security-admin-create-panel {
        margin-top: 1rem;
        padding: 1rem 1.1rem 1.1rem;
        border: 1px solid #cfdee8;
        border-left: 6px solid #1598b2;
        border-radius: 17px;
        background: linear-gradient(120deg, #f7fcfe 0%, #fff 24%);
        box-shadow: 0 14px 30px rgba(24, 62, 88, 0.08);
    }

    .security-admin-create-panel .panel-header {
        margin-bottom: 0.9rem;
        padding-bottom: 0.7rem;
        border-bottom: 1px solid #dbe7ee;
    }

    .security-admin-create-panel .panel-header h2 {
        margin: 0;
        color: #123d5c;
        font-size: 1.35rem;
    }

    .security-create-form {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.85rem 1rem;
    }

    .security-create-form .field {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.4rem;
        min-width: 0;
        margin: 0;
    }

    .security-create-form .field > span,
    .security-branch-access > legend {
        margin: 0;
        color: #46657d;
        font-size: 0.76rem;
        font-weight: 850;
        line-height: 1.2;
        letter-spacing: 0.055em;
        text-transform: uppercase;
    }

    .security-create-form input,
    .security-create-form select {
        width: 100%;
        min-height: 42px;
        padding: 0.5rem 0.75rem;
        border: 1px solid #bcd0df;
        border-radius: 10px;
        background: #fbfdff;
    }

    .security-create-form input:focus,
    .security-create-form select:focus {
        border-color: #168daf;
        outline: 3px solid rgba(22, 141, 175, 0.14);
        box-shadow: none;
    }

    .security-branch-access {
        grid-column: 1 / -1;
        min-width: 0;
        margin: 0.1rem 0 0;
        padding: 0.85rem;
        border: 1px solid #cfdee8;
        border-radius: 13px;
        background: linear-gradient(180deg, #f8fcfe 0%, #f2f8fb 100%);
    }

    .security-branch-access > legend {
        padding: 0 0.35rem;
    }

    .security-branch-options {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem;
        margin-top: 0.15rem;
    }

    .security-branch-choice {
        position: relative;
        display: grid;
        grid-template-columns: 24px minmax(0, 1fr);
        align-items: center;
        gap: 0.7rem;
        min-height: 58px;
        padding: 0.65rem 0.8rem;
        border: 1px solid #c9dae6;
        border-radius: 12px;
        color: #31546d;
        background: #fff;
        cursor: pointer;
        transition: border-color 150ms ease, background 150ms ease, box-shadow 150ms ease, transform 150ms ease;
    }

    .security-branch-choice:hover {
        border-color: #62b7ca;
        background: #f5fcfe;
        transform: translateY(-1px);
    }

    .security-branch-choice:has(input:checked) {
        border-color: #1598b2;
        background: linear-gradient(135deg, #eaf8fb 0%, #f7fdff 100%);
        box-shadow: inset 0 0 0 1px rgba(21, 152, 178, 0.16), 0 7px 16px rgba(21, 122, 154, 0.1);
    }

    .security-branch-choice input {
        appearance: none;
        width: 21px;
        height: 21px;
        min-height: 21px;
        margin: 0;
        padding: 0;
        border: 2px solid #8caabe;
        border-radius: 50%;
        background: #fff;
        box-shadow: inset 0 0 0 4px #fff;
        cursor: pointer;
    }

    .security-branch-choice input:checked {
        border-color: #118ba8;
        background: #118ba8;
    }

    .security-branch-choice__text {
        display: flex;
        flex-direction: column;
        gap: 0.12rem;
        min-width: 0;
    }

    .security-branch-choice__text strong {
        color: #173f5c;
        font-size: 0.88rem;
        line-height: 1.25;
    }

    .security-branch-choice__text small {
        color: #6b8294;
        font-size: 0.73rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .security-create-form .form-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-end;
        padding-top: 0.1rem;
    }

    .security-create-form .form-actions .btn-primary {
        min-width: 140px;
        background: linear-gradient(180deg, #138db5 0%, #08759b 100%);
        box-shadow: 0 8px 18px rgba(8, 117, 155, 0.2);
    }

    @media (max-width: 980px) {
        .security-create-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .security-create-form,
        .security-branch-options {
            grid-template-columns: 1fr;
        }
    }
</style>
<section class="page-head security-admin-head">
    <div>
        <h1><?= $editMode ? 'Edit User' : 'Super Admin Security Controls' ?></h1>
    </div>
    <div class="page-actions">
        <?php if ($editMode): ?>
            <a class="btn" href="<?= e(url('/admin/security')) ?>">Create User</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('/admin/security/users/edit')) ?>">Edit User</a>
        <?php endif; ?>
    </div>
</section>

<?php if (!$editMode): ?>
<section class="panel compact-panel security-admin-create-panel">
    <div class="panel-header">
        <h2>Create User</h2>
    </div>
    <form method="post" action="<?= e(url('/admin/security/users/create')) ?>" class="form-grid compact-form security-create-form">
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
        <fieldset class="field span-2 security-branch-access">
            <legend>Accessible Branches</legend>
            <div class="checkbox-grid security-branch-options">
                <?php foreach ($branches as $branch): ?>
                    <label class="checkbox-row security-branch-choice">
                        <input type="checkbox" name="branch_ids[]" value="<?= e((string) $branch['id']) ?>" checked>
                        <span class="security-branch-choice__text">
                            <strong><?= e((string) $branch['name']) ?></strong>
                            <small><?= e((string) $branch['code']) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Create User</button>
        </div>
    </form>
</section>
<?php else: ?>
<section class="panel compact-panel security-admin-picker-panel">
    <div class="panel-header">
        <div>
            <h2>Select User</h2>
            <p>Choose the user record you want to review or update.</p>
        </div>
    </div>
    <form method="get" action="<?= e(url('/admin/security/users/edit')) ?>" class="form-grid admin-select-form" data-security-admin-picker-form>
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
    </form>
</section>

<?php if ($targetUser !== null): ?>
    <section class="panel compact-panel" id="loaded-user-record" data-security-admin-loaded-record tabindex="-1">
        <div class="panel-header">
            <h2>Edit User Details</h2>
        </div>
        <form method="post" action="<?= e(url('/admin/security/users/update')) ?>" class="form-grid compact-form security-user-details-form">
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

            <fieldset class="field span-2 security-branch-access">
                <legend>Accessible Branches</legend>
                <div class="checkbox-grid security-branch-options">
                    <?php foreach ($branches as $branch): ?>
                        <label class="checkbox-row security-branch-choice">
                            <input type="checkbox" name="branch_ids[]" value="<?= e((string) $branch['id']) ?>" <?= in_array((int) $branch['id'], $branchAccessIds, true) ? 'checked' : '' ?>>
                            <span class="security-branch-choice__text">
                                <strong><?= e((string) $branch['name']) ?></strong>
                                <small><?= e((string) $branch['code']) ?></small>
                            </span>
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
    const picker = document.querySelector('[data-security-admin-picker]');
    const loadedRecord = document.querySelector('[data-security-admin-loaded-record]');

    if (pickerForm instanceof HTMLFormElement && picker instanceof HTMLSelectElement) {
        picker.addEventListener('change', () => {
            if (picker.value !== '') {
                pickerForm.requestSubmit();
            }
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
<?php endif; ?>
