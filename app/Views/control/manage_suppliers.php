<?php
$editing = is_array($editingSupplier ?? null) ? $editingSupplier : null;
$activeCount = count(array_filter($suppliers ?? [], static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
?>
<section class="page-head supplier-master-head">
    <div>
        <h1>Manage Suppliers</h1>
        <p>Correct supplier identity and contact details without moving or duplicating financial transactions.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/suppliers/settlements/global?start_new_payment=1&add_supplier=1')) ?>">Add Supplier</a>
        <a class="btn" href="<?= e(url('/master-data')) ?>">Master Data</a>
    </div>
</section>

<section class="stat-grid supplier-master-stats">
    <article class="stat-card"><div class="stat-label">Suppliers Shown</div><div class="stat-value"><?= e((string) count($suppliers ?? [])) ?></div></article>
    <article class="stat-card"><div class="stat-label">Active</div><div class="stat-value"><?= e((string) $activeCount) ?></div></article>
    <article class="stat-card"><div class="stat-label">Editing Rule</div><div class="stat-value stat-value-text">Same Supplier ID</div></article>
</section>

<?php if ($editing !== null): ?>
<section class="panel supplier-master-editor" id="supplier-editor">
    <div class="panel-header">
        <div>
            <h2>Edit Supplier</h2>
            <div class="panel-meta"><?= e((string) ($editing['code'] ?? '')) ?></div>
        </div>
        <a class="btn btn-sm" href="<?= e(url('/master-data/suppliers')) ?>">Cancel</a>
    </div>
    <form method="post" action="<?= e(url('/master-data/suppliers/update')) ?>" class="supplier-master-form">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="supplier_id" value="<?= e((string) ((int) ($editing['id'] ?? 0))) ?>">
        <label><span>Supplier Name</span><input type="text" name="name" maxlength="190" required value="<?= e((string) ($editing['name'] ?? '')) ?>"></label>
        <label><span>Branch</span><select name="branch_id" required><?php foreach ($branches ?? [] as $branch): ?><option value="<?= e((string) ((int) ($branch['id'] ?? 0))) ?>" <?= (int) ($editing['branch_id'] ?? 0) === (int) ($branch['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) ($branch['name'] ?? 'Branch')) ?></option><?php endforeach; ?></select></label>
        <label><span>Default Currency</span><select name="default_currency" required><?php foreach (['PKR', 'AED', 'USD'] as $currency): ?><option value="<?= e($currency) ?>" <?= (string) ($editing['default_currency'] ?? '') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></label>
        <label><span>Status</span><select name="is_active"><option value="1" <?= (int) ($editing['is_active'] ?? 0) === 1 ? 'selected' : '' ?>>Active</option><option value="0" <?= (int) ($editing['is_active'] ?? 0) !== 1 ? 'selected' : '' ?>>Inactive</option></select></label>
        <label><span>Contact Person</span><input type="text" name="contact_person" maxlength="190" value="<?= e((string) ($editing['contact_person'] ?? '')) ?>"></label>
        <label><span>Phone</span><input type="text" name="phone" maxlength="50" value="<?= e((string) ($editing['phone'] ?? '')) ?>"></label>
        <label><span>Email</span><input type="email" name="email" maxlength="190" value="<?= e((string) ($editing['email'] ?? '')) ?>"></label>
        <label class="span-2"><span>Address</span><input type="text" name="address" maxlength="500" value="<?= e((string) ($editing['address'] ?? '')) ?>"></label>
        <label class="span-2"><span>Notes</span><textarea name="notes" maxlength="4000" rows="2"><?= e((string) ($editing['notes'] ?? '')) ?></textarea></label>
        <div class="form-actions span-4"><button class="btn btn-primary" type="submit">Save Supplier</button></div>
    </form>
</section>
<?php endif; ?>

<section class="panel supplier-master-register">
    <div class="panel-header"><h2>Supplier Register</h2><div class="panel-meta"><?= e((string) count($suppliers ?? [])) ?> matching supplier(s)</div></div>
    <form method="get" action="<?= e(url('/master-data/suppliers')) ?>" class="supplier-master-filter">
        <label><span>Search</span><input type="search" name="q" value="<?= e((string) (($filters['q'] ?? ''))) ?>" placeholder="Name, code, contact, phone or email"></label>
        <label><span>Branch</span><select name="branch_id"><option value="0">All Accessible Branches</option><?php foreach ($branches ?? [] as $branch): ?><option value="<?= e((string) ((int) ($branch['id'] ?? 0))) ?>" <?= (int) ($filters['branch_id'] ?? 0) === (int) ($branch['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) ($branch['name'] ?? 'Branch')) ?></option><?php endforeach; ?></select></label>
        <label><span>Status</span><select name="status"><option value="all">All</option><option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
        <div class="supplier-master-filter-actions"><button class="btn btn-primary" type="submit">Search</button><a class="btn" href="<?= e(url('/master-data/suppliers')) ?>">Clear</a></div>
    </form>
    <div class="table-wrap">
        <table class="data-table supplier-master-table">
            <thead><tr><th>Code</th><th>Supplier</th><th>Branch</th><th>Curr.</th><th>Contact</th><th>Phone / Email</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (($suppliers ?? []) === []): ?>
                <tr><td colspan="8" class="empty-state">No supplier matched these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><strong><?= e((string) ($supplier['code'] ?? '')) ?></strong></td>
                        <td><strong><?= e((string) ($supplier['name'] ?? '')) ?></strong></td>
                        <td><?= e((string) ($supplier['branch_name'] ?? 'N/A')) ?></td>
                        <td><?= e((string) ($supplier['default_currency'] ?? '')) ?></td>
                        <td><?= e((string) (($supplier['contact_person'] ?? '') !== '' ? $supplier['contact_person'] : '—')) ?></td>
                        <td><span class="supplier-contact-stack"><?= e((string) (($supplier['phone'] ?? '') !== '' ? $supplier['phone'] : '—')) ?><small><?= e((string) ($supplier['email'] ?? '')) ?></small></span></td>
                        <td><span class="status-badge <?= (int) ($supplier['is_active'] ?? 0) === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) ($supplier['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td><a class="btn btn-sm" href="<?= e(url('/master-data/suppliers?edit=' . (int) ($supplier['id'] ?? 0) . '#supplier-editor')) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
