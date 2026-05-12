<?php
/** @var array $panel */
$register = (string) $panel['register'];
$rows = $panel['rows'] ?? [];
$columns = $panel['columns'] ?? [];
$fields = $panel['fields'] ?? [];
$editRecord = $panel['editRecord'] ?? null;
$isEditing = is_array($editRecord);
$formValues = [];

foreach ($fields as $field) {
    $name = (string) ($field['name'] ?? '');
    $default = $field['default'] ?? (($field['type'] ?? 'text') === 'select' ? (($field['options'][0]['value'] ?? '')) : '');
    $formValues[$name] = $editRecord[$name] ?? $default;
}
?>
<section class="panel compact-panel admin-register-panel" id="register-<?= e($register) ?>">
    <div class="panel-header">
        <div>
            <h2><?= e((string) $panel['title']) ?></h2>
            <div class="panel-meta"><?= e((string) $panel['description']) ?></div>
        </div>
        <div class="workspace-mode-chip workspace-mode-chip--light"><?= e((string) count($rows)) ?> Rows</div>
    </div>

    <div class="admin-register-body">
        <div class="admin-register-table">
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th><?= e((string) $column['label']) ?></th>
                        <?php endforeach; ?>
                        <th>Record</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td class="empty-cell" colspan="<?= e((string) (count($columns) + 2)) ?>">No rows yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php $value = $row[$column['key']] ?? null; ?>
                                    <td><?= e($value === null || $value === '' ? '—' : (string) $value) ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <span class="admin-record-tag <?= ((int) ($row['is_system'] ?? 0) === 1) ? 'is-system' : 'is-custom' ?>">
                                        <?= e((string) ($row['system_label'] ?? 'Custom')) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-row-actions">
                                        <a class="btn btn-sm" href="<?= e(url($pagePath . '?edit=' . $register . '&id=' . (int) $row['id'])) ?>#register-<?= e($register) ?>">Edit</a>
                                        <?php if ((int) ($row['is_system'] ?? 0) === 1): ?>
                                            <span class="admin-action-note">Locked</span>
                                        <?php else: ?>
                                            <form method="post" action="<?= e($deleteAction) ?>" onsubmit="return confirm('Delete this register row?');">
                                                <?= \App\Helpers\Csrf::input() ?>
                                                <input type="hidden" name="register" value="<?= e($register) ?>">
                                                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-register-form">
            <div class="admin-form-card">
                <div class="admin-form-card__head">
                    <strong><?= e($isEditing ? 'Edit ' . (string) $panel['title'] : 'Add ' . (string) $panel['title']) ?></strong>
                    <?php if ($isEditing): ?>
                        <a class="btn btn-sm" href="<?= e(url($pagePath)) ?>#register-<?= e($register) ?>">Clear</a>
                    <?php endif; ?>
                </div>

                <?php if ($isEditing && (int) ($editRecord['is_system'] ?? 0) === 1): ?>
                    <div class="admin-inline-note">System row: keep the key active and stable. Delete is intentionally blocked.</div>
                <?php endif; ?>

                <form method="post" action="<?= e($saveAction) ?>">
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="register" value="<?= e($register) ?>">
                    <input type="hidden" name="id" value="<?= e((string) ($editRecord['id'] ?? '')) ?>">

                    <div class="admin-entry-grid">
                        <?php foreach ($fields as $field): ?>
                            <?php
                            $name = (string) $field['name'];
                            $type = (string) ($field['type'] ?? 'text');
                            $stack = (bool) ($field['stack'] ?? false);
                            $value = $formValues[$name] ?? '';
                            ?>
                            <label class="admin-field <?= $stack ? 'admin-field--stack admin-field--full' : '' ?>">
                                <span><?= e((string) $field['label']) ?></span>
                                <?php if ($type === 'select'): ?>
                                    <select name="<?= e($name) ?>" <?= !empty($field['required']) ? 'required' : '' ?>>
                                        <?php foreach (($field['options'] ?? []) as $option): ?>
                                            <option value="<?= e((string) $option['value']) ?>" <?= (string) $value === (string) $option['value'] ? 'selected' : '' ?>>
                                                <?= e((string) $option['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea
                                        name="<?= e($name) ?>"
                                        <?= isset($field['maxlength']) ? 'maxlength="' . e((string) $field['maxlength']) . '"' : '' ?>
                                    ><?= e((string) $value) ?></textarea>
                                <?php else: ?>
                                    <input
                                        type="<?= e($type) ?>"
                                        name="<?= e($name) ?>"
                                        value="<?= e((string) $value) ?>"
                                        <?= !empty($field['required']) ? 'required' : '' ?>
                                        <?= isset($field['maxlength']) ? 'maxlength="' . e((string) $field['maxlength']) . '"' : '' ?>
                                        <?= isset($field['min']) ? 'min="' . e((string) $field['min']) . '"' : '' ?>
                                    >
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions top-gap">
                        <button class="btn btn-primary" type="submit"><?= e($isEditing ? 'Update' : 'Add') ?></button>
                        <a class="btn" href="<?= e(url($pagePath)) ?>#register-<?= e($register) ?>">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
