<?php
$recentOffsets = is_array($recentOffsets ?? null) ? $recentOffsets : [];
$selectedOffset = is_array($selectedOffset ?? null) ? $selectedOffset : null;
$selectedAccountAllocations = is_array($selectedAccountAllocations ?? null) ? $selectedAccountAllocations : [];
$selectedPayableAllocations = is_array($selectedPayableAllocations ?? null) ? $selectedPayableAllocations : [];
$money = static fn (mixed $value): string => number_format((float) $value, 2);
$postedCount = count(array_filter($recentOffsets, static fn (array $row): bool => ($row['status'] ?? '') === 'posted'));
$voidCount = count($recentOffsets) - $postedCount;
$sumAdjusted = static fn (array $rows): float => round(array_reduce(
    $rows,
    static fn (float $total, array $row): float => $total + (float) ($row['adjusted_amount'] ?? $row['allocated_amount'] ?? 0),
    0.0
), 2);
?>
<section class="page-head linked-settlement-head">
    <div>
        <h1>Automatic Linked Settlements</h1>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/master-data/account-supplier-links')) ?>">Manage Links</a>
    </div>
</section>

<section class="stat-grid linked-settlement-stats">
    <article class="stat-card"><div class="stat-label">Active Settlements</div><div class="stat-value"><?= e((string) $postedCount) ?></div></article>
    <article class="stat-card"><div class="stat-label">Reversed</div><div class="stat-value"><?= e((string) $voidCount) ?></div></article>
</section>

<?php if ($selectedOffset !== null): ?>
<section class="panel compact-panel linked-settlement-detail">
    <div class="panel-header">
        <div>
            <h2>Adjusted Invoice Details</h2>
            <p class="panel-meta">
                <?= e((string) ($selectedOffset['offset_no'] ?? 'Settlement')) ?>
                · <?= e((string) ($selectedOffset['currency'] ?? '')) ?>
                <?= e($money($selectedOffset['amount'] ?? 0)) ?>
            </p>
        </div>
        <a class="btn btn-sm" href="<?= e(url('/linked-party-settlements')) ?>">Close Details</a>
    </div>
    <div class="linked-settlement-allocation-grid">
        <article class="linked-settlement-allocation-card">
            <div class="linked-settlement-allocation-head">
                <div>
                    <h3>Account-Holder Invoices</h3>
                    <small><?= e((string) count($selectedAccountAllocations)) ?> invoice(s)</small>
                </div>
                <strong><?= e((string) ($selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedAccountAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Service</th><th>Invoice Amount</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedAccountAllocations === []): ?>
                        <tr><td colspan="4" class="empty-cell">No account-holder invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedAccountAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
                                <td><?= e((string) ($allocation['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($allocation['original_amount'] ?? 0)) ?></td>
                                <td><strong><?= e($money($allocation['adjusted_amount'] ?? $allocation['allocated_amount'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="linked-settlement-allocation-card">
            <div class="linked-settlement-allocation-head">
                <div>
                    <h3>Supplier Invoices</h3>
                    <small><?= e((string) count($selectedPayableAllocations)) ?> invoice(s)</small>
                </div>
                <strong><?= e((string) ($selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedPayableAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Service</th><th>Supplier Cost</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedPayableAllocations === []): ?>
                        <tr><td colspan="4" class="empty-cell">No supplier invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedPayableAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
                                <td><?= e((string) ($allocation['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($allocation['original_amount'] ?? 0)) ?></td>
                                <td><strong><?= e($money($allocation['adjusted_amount'] ?? $allocation['allocated_amount'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="panel compact-panel linked-settlement-history">
    <div class="panel-header linked-settlement-toolbar">
        <div>
            <h2>Settlement History</h2>
            <p class="panel-meta">Every automatic adjustment is recorded here and can be reversed with an audit reason.</p>
        </div>
        <div class="linked-settlement-toolbar-actions">
            <button class="btn btn-sm" type="button" id="linked-settlement-expand-button">Open Wide View</button>
            <button class="btn btn-sm linked-settlement-close" type="button" id="linked-settlement-close-button">Close Wide View</button>
        </div>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table linked-settlement-table">
            <thead><tr><th>Date</th><th>Settlement</th><th>Account Holder</th><th>Supplier</th><th>Branch</th><th>Curr.</th><th>Adjusted</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($recentOffsets === []): ?>
                <tr><td colspan="9" class="empty-cell">No automatic linked settlement has been recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentOffsets as $offset): ?>
                    <?php $isPosted = ($offset['status'] ?? '') === 'posted'; ?>
                    <tr>
                        <td><?= e((string) ($offset['offset_date'] ?? '')) ?></td>
                        <td><strong><?= e((string) ($offset['offset_no'] ?? '')) ?></strong></td>
                        <td><?= e((string) ($offset['business_source_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['supplier_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['branch_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['currency'] ?? '')) ?></td>
                        <td><strong><?= e($money($offset['amount'] ?? 0)) ?></strong></td>
                        <td><span class="admin-record-tag <?= $isPosted ? 'is-custom' : 'is-system' ?>"><?= e($isPosted ? 'Adjusted' : 'Reversed') ?></span></td>
                        <td>
                            <div class="linked-settlement-actions">
                                <a class="btn btn-sm" href="<?= e(url('/linked-party-settlements?offset_id=' . (int) $offset['id'])) ?>">View Invoices</a>
                                <?php if ($isPosted): ?>
                                    <details class="linked-settlement-reverse">
                                        <summary class="btn btn-sm btn-danger">Reverse</summary>
                                        <form method="post" action="<?= e(url('/linked-party-settlements/void')) ?>">
                                            <?= \App\Helpers\Csrf::input() ?>
                                            <input type="hidden" name="offset_id" value="<?= e((string) $offset['id']) ?>">
                                            <input type="text" name="reason" maxlength="2000" required placeholder="Reason for reversal">
                                            <button class="btn btn-sm btn-danger" type="submit">Confirm</button>
                                        </form>
                                    </details>
                                <?php else: ?>
                                    <small><?= e((string) ($offset['void_reason'] ?? 'Reversed')) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
    .linked-settlement-stats { grid-template-columns: repeat(2, minmax(170px, 250px)); justify-content: start; }
    .linked-settlement-history { padding: 14px 16px; }
    .linked-settlement-history.is-expanded {
        position: fixed;
        inset: 1rem;
        z-index: 80;
        display: flex;
        flex-direction: column;
        margin: 0;
        max-width: none;
        overflow: hidden;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        box-shadow: 0 24px 70px rgba(15, 36, 54, 0.28);
    }
    .linked-settlement-history.is-expanded::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(2px);
    }
    .linked-settlement-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .linked-settlement-toolbar-actions {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
    }
    .linked-settlement-close { display: none; }
    .linked-settlement-history.is-expanded .linked-settlement-close { display: inline-flex; }
    .linked-settlement-history.is-expanded > .dense-table-wrap {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }
    .linked-settlement-history.is-expanded .linked-settlement-table {
        width: 100%;
        min-width: 1180px;
    }
    .linked-settlement-table { table-layout: fixed; min-width: 1180px; }
    .linked-settlement-table th,
    .linked-settlement-table td { vertical-align: middle; padding: 13px 12px; }
    .linked-settlement-table th:nth-child(1) { width: 96px; }
    .linked-settlement-table th:nth-child(2) { width: 260px; }
    .linked-settlement-table th:nth-child(3) { width: 145px; }
    .linked-settlement-table th:nth-child(4) { width: 170px; }
    .linked-settlement-table th:nth-child(5) { width: 120px; }
    .linked-settlement-table th:nth-child(6) { width: 72px; }
    .linked-settlement-table th:nth-child(7) { width: 120px; }
    .linked-settlement-table th:nth-child(8) { width: 120px; }
    .linked-settlement-table th:nth-child(9) { width: 235px; }
    .linked-settlement-table td:nth-child(1),
    .linked-settlement-table td:nth-child(2),
    .linked-settlement-table td:nth-child(6),
    .linked-settlement-table td:nth-child(7) { white-space: nowrap; }
    .linked-settlement-table td:nth-child(2) strong { font-size: .93rem; letter-spacing: -.01em; }
    .linked-settlement-actions { display: flex; align-items: center; gap: 9px; white-space: nowrap; }
    .linked-settlement-actions .btn { min-width: 0; white-space: nowrap; }
    .linked-settlement-detail { padding: 14px 16px; border-left: 5px solid #2f9fc8; }
    .linked-settlement-allocation-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .linked-settlement-allocation-card { min-width: 0; overflow: hidden; border: 1px solid #cbddeb; border-radius: 14px; background: #fff; }
    .linked-settlement-allocation-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; background: #eef7fc; border-bottom: 1px solid #cbddeb; }
    .linked-settlement-allocation-head h3 { margin: 0 0 3px; }
    .linked-settlement-allocation-head small { color: #5d7185; }
    .linked-settlement-allocation-card .dense-table-wrap { border: 0; border-radius: 0; }
    .linked-settlement-reverse { position: relative; }
    .linked-settlement-reverse > summary { list-style: none; cursor: pointer; }
    .linked-settlement-reverse > summary::-webkit-details-marker { display: none; }
    .linked-settlement-reverse form { display: flex; gap: 7px; min-width: 300px; margin-top: 7px; }
    .linked-settlement-reverse input { min-width: 210px; }
    @media (max-width: 980px) {
        .linked-settlement-allocation-grid { grid-template-columns: 1fr; }
    }
</style>

<script>
(() => {
    const panel = document.querySelector('.linked-settlement-history');
    const openButton = document.getElementById('linked-settlement-expand-button');
    const closeButton = document.getElementById('linked-settlement-close-button');

    if (!panel || !openButton || !closeButton) {
        return;
    }

    const openWideView = () => {
        panel.classList.add('is-expanded');
        document.body.style.overflow = 'hidden';
        closeButton.focus();
    };

    const closeWideView = () => {
        panel.classList.remove('is-expanded');
        document.body.style.overflow = '';
        openButton.focus();
    };

    openButton.addEventListener('click', openWideView);
    closeButton.addEventListener('click', closeWideView);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('is-expanded')) {
            closeWideView();
        }
    });
})();
</script>
