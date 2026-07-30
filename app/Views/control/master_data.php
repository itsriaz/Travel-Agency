<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
$visiblePanels = array_values(array_filter(
    $panels ?? [],
    static fn (array $panel): bool => (string) ($panel['register'] ?? '') !== 'supplier_modes'
));
$totalRows = array_sum(array_map(static fn (array $panel): int => count($panel['rows'] ?? []), $visiblePanels));
$activeRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_active'] ?? 0)) === 1)),
    $visiblePanels
));
$systemRows = array_sum(array_map(
    static fn (array $panel): int => count(array_filter($panel['rows'] ?? [], static fn (array $row): bool => ((int) ($row['is_system'] ?? 0)) === 1)),
    $visiblePanels
));
?>
<section class="page-head master-data-head">
    <div>
        <h1>Master Data</h1>
        <p>Maintain the registers and business records used throughout bookings and accounting.</p>
    </div>
</section>

<div class="context-strip workspace-context-strip">
    <span>Accessible Branches:</span>
    <strong><?= e($branchLabel !== '' ? $branchLabel : 'Restricted') ?></strong>
    <span class="workspace-context-divider">|</span>
    <span>Scope:</span>
    <strong>Super Admin Register Control</strong>
</div>

<section class="stat-grid master-data-stats">
    <article class="stat-card">
        <div class="stat-label">Registers</div>
        <div class="stat-value"><?= e((string) count($visiblePanels)) ?></div>
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

<section class="panel compact-panel admin-register-index master-data-navigator">
    <div class="panel-header">
        <div>
            <h2>Register Navigator</h2>
            <p>Open a management tool or jump directly to a register.</p>
        </div>
        <div class="panel-meta"><?= e((string) $totalRows) ?> total setup rows</div>
    </div>
    <nav class="master-data-nav" aria-label="Master data tools and registers">
        <div class="master-data-nav__group">
            <span class="master-data-nav__label">Management</span>
            <div class="master-data-nav__links">
                <a class="master-data-nav-link" href="<?= e(url('/suppliers/settlements/global?start_new_payment=1&add_supplier=1')) ?>">Add Supplier</a>
                <a class="master-data-nav-link" href="<?= e(url('/master-data/suppliers')) ?>">Manage Suppliers</a>
                <a class="master-data-nav-link" href="<?= e(url('/master-data/account-supplier-links')) ?>">Account and Supplier Links</a>
            </div>
        </div>
        <div class="master-data-nav__group">
            <span class="master-data-nav__label">Registers</span>
            <div class="master-data-nav__links">
        <?php foreach ($visiblePanels as $panel): ?>
                <a class="master-data-nav-link" href="#register-<?= e((string) $panel['register']) ?>"><?= e((string) $panel['title']) ?></a>
        <?php endforeach; ?>
            </div>
        </div>
    </nav>
</section>

<?php foreach ($visiblePanels as $panelIndex => $panel): ?>
    <?php
    $registerTone = ((int) $panelIndex % 2) === 0 ? 'blue' : 'teal';
    $pagePath = '/master-data';
    $saveAction = url('/master-data/save');
    $deleteAction = url('/master-data/delete');
    require base_path('/app/Views/control/partials/register_panel.php');
    ?>
<?php endforeach; ?>
