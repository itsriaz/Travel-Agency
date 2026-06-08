<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
$formatAmount = static fn (float $amount): string => number_format($amount, 2);
$totalRows = array_sum(array_map(static fn (array $panel): int => count($panel['rows'] ?? []), $panels ?? []));
$systemRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_system'] ?? 0)) === 1)),
    $panels ?? []
));
$journalRows = $accountingFoundation['journalPreview'] ?? [];
$previewBookingOptions = is_array($previewBookingOptions ?? null) ? $previewBookingOptions : [];
$selectedPreviewBooking = is_array($selectedPreviewBooking ?? null) ? $selectedPreviewBooking : null;
$previewSearchTerm = trim((string) ($previewSearchTerm ?? ''));
$previewBookingId = (int) ($previewBookingId ?? 0);
$previewBookingReference = trim((string) ($selectedPreviewBooking['booking_reference'] ?? ''));
$previewBookingLabel = $previewBookingReference !== ''
    ? trim($previewBookingReference . ' / ' . (string) ($selectedPreviewBooking['lead_traveler_name'] ?? 'Booking') . ' / ' . (string) ($selectedPreviewBooking['branch_name'] ?? ''))
    : 'No booking selected';
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
    <span>Selected Booking:</span>
    <strong><?= e($previewBookingLabel) ?></strong>
</div>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Accounting Setup Sections</div>
        <div class="stat-value"><?= e((string) count($panels)) ?></div>
        <div class="stat-note">Groups of accounting settings shown below.</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Locked System Setup</div>
        <div class="stat-value"><?= e((string) $systemRows) ?></div>
        <div class="stat-note">Core accounts/rules protected from accidental changes.</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Posted Journal Lines</div>
        <div class="stat-value"><?= e((string) count($journalRows)) ?></div>
        <div class="stat-note">Accounting entries posted for the selected booking.</div>
    </article>
</section>

<section class="control-grid">
    <article class="panel compact-panel">
        <div class="panel-header">
            <h2>Booking Accounting Preview</h2>
        </div>
        <form method="get" action="<?= e(url('/accounting-engine')) ?>" class="station-form-grid station-form-grid--12">
            <input type="hidden" name="register" value="<?= e((string) ($_GET['register'] ?? '')) ?>">
            <?php if ((int) ($_GET['id'] ?? 0) > 0): ?>
                <input type="hidden" name="id" value="<?= e((string) ((int) ($_GET['id'] ?? 0))) ?>">
            <?php endif; ?>
            <label class="station-field span-4">
                <span>Find Booking</span>
                <input type="text" name="preview_q" value="<?= e($previewSearchTerm) ?>" placeholder="Booking ref / traveler / passport / receipt">
            </label>
            <label class="station-field span-6">
                <span>Booking to Preview</span>
                <select name="preview_booking_id">
                    <option value="0">Choose a booking to preview</option>
                    <?php foreach ($previewBookingOptions as $bookingOption): ?>
                        <?php $optionId = (int) ($bookingOption['id'] ?? 0); ?>
                        <?php $optionLabel = trim(
                            (string) ($bookingOption['booking_reference'] ?? '')
                            . ' / '
                            . (string) ($bookingOption['lead_traveler_name'] ?? 'Traveler')
                            . ' / '
                            . (string) ($bookingOption['branch_name'] ?? 'Branch')
                            . ' / '
                            . (string) ($bookingOption['booking_date'] ?? '')
                        ); ?>
                        <option value="<?= e((string) $optionId) ?>" <?= $optionId === $previewBookingId ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="station-field span-2" style="align-self:end;">
                <button class="btn btn-primary" type="submit" style="width:100%;">Show Accounting</button>
            </div>
        </form>
    </article>

    <article class="panel compact-panel">
        <div class="panel-header">
            <h2>Financial Snapshot</h2>
        </div>
        <?php if ($previewBookingReference === ''): ?>
            <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                Select a live booking to preview its posted accounting, customer receivable, and supplier payable position.
            </div>
        <?php endif; ?>
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
