<?php
$links = is_array($links ?? null) ? $links : [];
$availableAccounts = is_array($availableAccounts ?? null) ? $availableAccounts : [];
$availableSuppliers = is_array($availableSuppliers ?? null) ? $availableSuppliers : [];
$recentHistory = is_array($recentHistory ?? null) ? $recentHistory : [];
?>
<section class="page-head counterparty-link-head">
    <div>
        <h1>Account and Supplier Links</h1>
        <p>Identify when the same business party is both an account holder and a supplier.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/linked-party-settlements')) ?>">View Adjustments</a>
        <a class="btn" href="<?= e(url('/master-data')) ?>#register-business_sources">Back to Master Data</a>
    </div>
</section>

<section class="stat-grid counterparty-link-stats">
    <article class="stat-card">
        <div class="stat-label">Linked Parties</div>
        <div class="stat-value"><?= e((string) count($links)) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Available Accounts</div>
        <div class="stat-value"><?= e((string) count($availableAccounts)) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Available Suppliers</div>
        <div class="stat-value"><?= e((string) count($availableSuppliers)) ?></div>
    </article>
</section>

<section class="panel compact-panel counterparty-link-create">
    <div class="panel-header">
        <div>
            <h2>Create Link</h2>
            <p class="panel-meta">Matching balances in the same branch and currency will be adjusted automatically without moving cash or bank money.</p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/master-data/account-supplier-links/save')) ?>" class="counterparty-link-form">
        <?= \App\Helpers\Csrf::input() ?>
        <label class="counterparty-link-field">
            <span>Account Holder</span>
            <select id="link-business-source" name="business_source_id" required>
                <option value="">Select account holder</option>
                <?php foreach ($availableAccounts as $account): ?>
                    <option value="<?= e((string) $account['id']) ?>">
                        <?= e(trim((string) ($account['name'] ?? '')) . ' / ' . trim((string) ($account['code'] ?? ''))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="counterparty-link-field">
            <span>Supplier</span>
            <select id="link-supplier" name="supplier_id" required>
                <option value="">Select supplier</option>
                <?php foreach ($availableSuppliers as $supplier): ?>
                    <option value="<?= e((string) $supplier['id']) ?>">
                        <?= e(trim((string) ($supplier['name'] ?? '')) . ' / ' . trim((string) ($supplier['code'] ?? '')) . ' / ' . trim((string) ($supplier['branch_name'] ?? '')) . ' / ' . trim((string) ($supplier['default_currency'] ?? ''))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="counterparty-link-field counterparty-link-field--note">
            <span>Note (Optional)</span>
            <input type="text" name="reason" maxlength="1000" placeholder="Why these two records represent the same party">
        </label>
        <button class="btn btn-primary counterparty-link-submit" type="submit" <?= ($availableAccounts === [] || $availableSuppliers === []) ? 'disabled' : '' ?>>Save Link</button>
    </form>
</section>

<section class="panel compact-panel counterparty-link-register">
    <div class="panel-header">
        <div>
            <h2>Current Links</h2>
            <p class="panel-meta">Search by account, supplier, branch, currency or code.</p>
        </div>
        <input class="counterparty-link-table-search" type="search" placeholder="Search current links" data-link-table-search>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table counterparty-link-table">
            <thead>
            <tr>
                <th>Account Holder</th>
                <th>Supplier</th>
                <th>Branch</th>
                <th>Curr.</th>
                <th>Linked By</th>
                <th>Linked At</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody data-link-table-body>
            <?php if ($links === []): ?>
                <tr><td class="empty-cell" colspan="7">No account holder is linked to a supplier yet.</td></tr>
            <?php else: ?>
                <?php foreach ($links as $link): ?>
                    <?php $searchText = strtolower(implode(' ', array_map('strval', array_filter([
                        $link['business_source_name'] ?? '', $link['business_source_code'] ?? '',
                        $link['supplier_name'] ?? '', $link['supplier_code'] ?? '',
                        $link['supplier_branch_name'] ?? '', $link['supplier_currency'] ?? '',
                    ])))); ?>
                    <tr data-link-row data-search-text="<?= e($searchText) ?>">
                        <td><strong><?= e((string) ($link['business_source_name'] ?? '')) ?></strong><small><?= e((string) ($link['business_source_code'] ?? '')) ?></small></td>
                        <td><strong><?= e((string) ($link['supplier_name'] ?? '')) ?></strong><small><?= e((string) ($link['supplier_code'] ?? '')) ?></small></td>
                        <td><?= e((string) ($link['supplier_branch_name'] ?? 'N/A')) ?></td>
                        <td><?= e((string) ($link['supplier_currency'] ?? 'N/A')) ?></td>
                        <td><?= e((string) ($link['linked_by_name'] ?? 'System')) ?></td>
                        <td><?= e((string) ($link['linked_at'] ?? '')) ?></td>
                        <td>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/linked-party-settlements')) ?>">View Adjustments</a>
                            <form method="post" action="<?= e(url('/master-data/account-supplier-links/unlink')) ?>" class="counterparty-unlink-form" data-unlink-form>
                                <?= \App\Helpers\Csrf::input() ?>
                                <input type="hidden" name="link_id" value="<?= e((string) $link['id']) ?>">
                                <input type="text" name="reason" maxlength="1000" required placeholder="Reason for unlinking">
                                <button class="btn btn-sm btn-danger" type="submit">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<details class="panel compact-panel counterparty-link-history">
    <summary>Recent Link History <span><?= e((string) count($recentHistory)) ?> events</span></summary>
    <div class="dense-table-wrap">
        <table class="dense-table">
            <thead><tr><th>Date</th><th>Action</th><th>Account Holder</th><th>Supplier</th><th>Administrator</th><th>Reason</th></tr></thead>
            <tbody>
            <?php if ($recentHistory === []): ?>
                <tr><td class="empty-cell" colspan="6">No link history recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentHistory as $event): ?>
                    <tr>
                        <td><?= e((string) ($event['created_at'] ?? '')) ?></td>
                        <td><span class="admin-record-tag <?= ($event['action'] ?? '') === 'linked' ? 'is-custom' : 'is-system' ?>"><?= e(ucfirst((string) ($event['action'] ?? ''))) ?></span></td>
                        <td><?= e((string) ($event['business_source_name'] ?? '')) ?></td>
                        <td><?= e((string) ($event['supplier_name'] ?? '')) ?></td>
                        <td><?= e((string) ($event['actor_name'] ?? 'System')) ?></td>
                        <td><?= e((string) ($event['reason'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
