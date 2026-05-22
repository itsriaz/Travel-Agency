<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
$formatAmount = static fn (float $amount): string => number_format($amount, 2);
$totalRows = array_sum(array_map(static fn (array $panel): int => count($panel['rows'] ?? []), $panels ?? []));
$systemRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_system'] ?? 0)) === 1)),
    $panels ?? []
));
$journalRows = $accountingFoundation['journalPreview'] ?? [];
?>
<section class="page-head">
    <div>
        <h1>Accounting Engine</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/workspace')) ?>">Open Booking Workspace</a>
        <a class="btn" href="<?= e(url('/master-data')) ?>">Open Master Data</a>
    </div>
</section>

<div class="context-strip workspace-context-strip">
    <span>Accessible Branches:</span>
    <strong><?= e($branchLabel !== '' ? $branchLabel : 'Restricted') ?></strong>
    <span class="workspace-context-divider">|</span>
    <span>Engine Scope:</span>
    <strong>Active</strong>
</div>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Setup Areas</div>
        <div class="stat-value"><?= e((string) count($panels)) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Protected Setup</div>
        <div class="stat-value"><?= e((string) $systemRows) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Current Journal Rows</div>
        <div class="stat-value"><?= e((string) count($journalRows)) ?></div>
    </article>
</section>

<section class="control-grid">
    <article class="panel compact-panel">
        <div class="panel-header">
            <h2>Financial Snapshot</h2>
        </div>
        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                <tr>
                    <th>Metric</th>
                    <th>PKR</th>
                    <th>AED</th>
                    <th>USD</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Total Receivable</td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceivable']['PKR'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceivable']['AED'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceivable']['USD'] ?? 0)))) ?></td>
                </tr>
                <tr>
                    <td>Total Payable</td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalPayable']['PKR'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalPayable']['AED'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalPayable']['USD'] ?? 0)))) ?></td>
                </tr>
                <tr>
                    <td>Total Received</td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceived']['PKR'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceived']['AED'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalReceived']['USD'] ?? 0)))) ?></td>
                </tr>
                <tr>
                    <td>Total Outstanding</td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalOutstanding']['PKR'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalOutstanding']['AED'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['totalOutstanding']['USD'] ?? 0)))) ?></td>
                </tr>
                <tr>
                    <td>Profit / Loss</td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['profitLossSnapshot']['PKR'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['profitLossSnapshot']['AED'] ?? 0)))) ?></td>
                    <td><?= e($formatAmount((float) (($accountingFoundation['summary']['profitLossSnapshot']['USD'] ?? 0)))) ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </article>
</section>

<details class="panel compact-panel admin-advanced-panel">
    <summary>Advanced Accounting Setup</summary>
    <section class="admin-register-index top-gap">
        <div class="panel-header">
            <h2>Setup Navigator</h2>
        </div>
        <div class="station-chip-row">
            <?php foreach ($panels as $panel): ?>
                <a class="station-chip" href="#register-<?= e((string) $panel['register']) ?>"><?= e((string) $panel['title']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php foreach ($panels as $panel): ?>
        <?php
        $pagePath = '/accounting-engine';
        $saveAction = url('/accounting-engine/save');
        $deleteAction = url('/accounting-engine/delete');
        require base_path('/app/Views/control/partials/register_panel.php');
        ?>
    <?php endforeach; ?>

    <?php if ($journalRows !== []): ?>
        <section class="panel compact-panel">
            <div class="panel-header">
                <h2>Journal Entries</h2>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead>
                    <tr>
                        <th>Event</th>
                        <th>Reference</th>
                        <th>Currency</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($journalRows as $journalRow): ?>
                        <tr>
                            <td><?= e((string) $journalRow['event']) ?></td>
                            <td><?= e((string) $journalRow['reference']) ?></td>
                            <td><?= e((string) $journalRow['currency']) ?></td>
                            <td><?= e((string) $journalRow['debit']) ?></td>
                            <td><?= e((string) $journalRow['credit']) ?></td>
                            <td><?= e($formatAmount((float) $journalRow['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</details>
