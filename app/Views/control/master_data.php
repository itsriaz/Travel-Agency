<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
$totalRows = array_sum(array_map(static fn (array $panel): int => count($panel['rows'] ?? []), $panels ?? []));
$activeRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_active'] ?? 0)) === 1)),
    $panels ?? []
));
$systemRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_system'] ?? 0)) === 1)),
    $panels ?? []
));
?>
<section class="page-head">
    <div>
        <h1>Master Data</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/workspace')) ?>">Open Booking Workspace</a>
        <a class="btn" href="<?= e(url('/accounting-engine')) ?>">Open Accounting Engine</a>
    </div>
</section>

<div class="context-strip workspace-context-strip">
    <span>Accessible Branches:</span>
    <strong><?= e($branchLabel !== '' ? $branchLabel : 'Restricted') ?></strong>
    <span class="workspace-context-divider">|</span>
    <span>Scope:</span>
    <strong>Super Admin Register Control</strong>
</div>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Registers</div>
        <div class="stat-value"><?= e((string) count($panels)) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Active Rows</div>
        <div class="stat-value"><?= e((string) $activeRows) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">System Locked</div>
        <div class="stat-value"><?= e((string) $systemRows) ?></div>
    </article>
</section>

<section class="panel compact-panel admin-register-index">
    <div class="panel-header">
        <h2>Register Navigator</h2>
        <div class="panel-meta"><?= e((string) $totalRows) ?> total setup rows</div>
    </div>
    <div class="station-chip-row">
        <?php foreach ($panels as $panel): ?>
            <a class="station-chip" href="#register-<?= e((string) $panel['register']) ?>"><?= e((string) $panel['title']) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<?php foreach ($panels as $panel): ?>
    <?php
    $pagePath = '/master-data';
    $saveAction = url('/master-data/save');
    $deleteAction = url('/master-data/delete');
    require base_path('/app/Views/control/partials/register_panel.php');
    ?>
<?php endforeach; ?>
