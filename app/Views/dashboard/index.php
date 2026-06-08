<?php

declare(strict_types=1);

$analytics = $dashboardAnalytics ?? ['periods' => [], 'expenseByBranch' => [], 'expenseByCategory' => []];
$branchLocalPeriods = $analytics['branchLocalPeriods'] ?? [];
$expenseByBranch = $analytics['expenseByBranch'] ?? [];
$expenseByCategory = $analytics['expenseByCategory'] ?? [];
$branchLocalChartRows = is_array($branchLocalPeriods['this_month']['branches'] ?? null) ? $branchLocalPeriods['this_month']['branches'] : [];
$branchLocalChartMax = max(1.0, ...array_map(static fn (array $row): float => max(
    abs((float) ($row['sales'] ?? 0)),
    abs((float) ($row['supplier_cost'] ?? 0)),
    abs((float) ($row['expenses'] ?? 0)),
    abs((float) ($row['net_profit'] ?? 0))
), $branchLocalChartRows ?: [['sales' => 0, 'supplier_cost' => 0, 'expenses' => 0, 'net_profit' => 0]]));
?>

<section class="page-head">
    <div>
        <h1>Super Admin Dashboard</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/workspace')) ?>">Open Booking Workspace</a>
        <a class="btn" href="<?= e(url('/reports?report=reminder_hub')) ?>">Reminder Hub</a>
        <a class="btn" href="<?= e(url('/reports?report=management_summary')) ?>">Open Reports</a>
        <a class="btn" href="<?= e(url('/expenses')) ?>">Business Expenses</a>
    </div>
</section>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Branches</div>
        <div class="stat-value">2</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Profit Model</div>
        <div class="stat-value">Branch Currency</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">FX Basis</div>
        <div class="stat-value">Actual Allocations</div>
    </article>
</section>

<section class="dashboard-chart-grid">
    <article class="panel dashboard-chart-panel">
        <div class="panel-header">
            <h2>Branch Local Profit</h2>
        </div>
        <?php if ($branchLocalChartRows === []): ?>
            <div class="empty-cell">No branch-local profit data available yet.</div>
        <?php else: ?>
            <div class="dashboard-profit-chart" aria-label="Branch local profit chart for this month">
                <?php foreach ($branchLocalChartRows as $row): ?>
                    <div class="dashboard-profit-row">
                        <div class="dashboard-profit-label"><?= e((string) ($row['branch_name'] ?? 'Branch')) ?></div>
                        <div class="dashboard-profit-bars">
                            <div class="dashboard-bar-line">
                                <span>Sales</span>
                                <div><i class="dashboard-bar dashboard-bar--gross" style="width: <?= e((string) max(2, min(100, round((((float) ($row['sales'] ?? 0)) / $branchLocalChartMax) * 100)))) ?>%;"></i></div>
                                <strong><?= e((string) ($row['sales_label'] ?? '0.00')) ?></strong>
                            </div>
                            <div class="dashboard-bar-line">
                                <span>Cost</span>
                                <div><i class="dashboard-bar dashboard-bar--expense" style="width: <?= e((string) max(2, min(100, round((((float) ($row['supplier_cost'] ?? 0)) / $branchLocalChartMax) * 100)))) ?>%;"></i></div>
                                <strong><?= e((string) ($row['supplier_cost_label'] ?? '0.00')) ?></strong>
                            </div>
                            <div class="dashboard-bar-line">
                                <span>Net</span>
                                <div><i class="dashboard-bar dashboard-bar--net<?= (float) ($row['net_profit'] ?? 0) < 0 ? ' dashboard-bar--negative' : '' ?>" style="width: <?= e((string) max(2, min(100, round((abs((float) ($row['net_profit'] ?? 0)) / $branchLocalChartMax) * 100)))) ?>%;"></i></div>
                                <strong><?= e((string) ($row['net_profit_label'] ?? '0.00')) ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel dashboard-chart-panel">
        <div class="panel-header">
            <h2>Branch Expenses</h2>
        </div>
        <?php if ($branchLocalChartRows === []): ?>
            <div class="empty-cell">No expense chart data available yet.</div>
        <?php else: ?>
            <div class="dashboard-expense-chart" aria-label="Branch local expense chart for this month">
                <?php foreach ($branchLocalChartRows as $row): ?>
                    <div class="dashboard-expense-row">
                        <div>
                            <strong><?= e((string) ($row['branch_name'] ?? 'Branch')) ?></strong>
                            <span><?= e((string) ($row['base_currency'] ?? '')) ?></span>
                        </div>
                        <div class="dashboard-expense-bar"><i style="width: <?= e((string) max(2, min(100, round((((float) ($row['expenses'] ?? 0)) / $branchLocalChartMax) * 100)))) ?>%;"></i></div>
                        <em><?= e((string) ($row['expenses_label'] ?? '0.00')) ?></em>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Branch Local Net Profit</h2>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Branch</th>
                    <th>Currency</th>
                    <th>Sales</th>
                    <th>Supplier Cost</th>
                    <th>Expenses</th>
                    <th>Net Profit</th>
                    <th>FX Pending</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($branchLocalPeriods === []): ?>
                    <tr>
                        <td colspan="8">No dashboard analytics available yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($branchLocalPeriods as $period): ?>
                        <?php foreach (($period['branches'] ?? []) as $branchRow): ?>
                            <tr>
                                <td><?= e((string) ($period['label'] ?? 'Period')) ?></td>
                                <td><?= e((string) ($branchRow['branch_name'] ?? 'Branch')) ?></td>
                                <td><?= e((string) ($branchRow['base_currency'] ?? 'PKR')) ?></td>
                                <td><?= e((string) ($branchRow['sales_label'] ?? '0.00')) ?></td>
                                <td><?= e((string) ($branchRow['supplier_cost_label'] ?? '0.00')) ?></td>
                                <td><?= e((string) ($branchRow['expenses_label'] ?? '0.00')) ?></td>
                                <td><?= e((string) ($branchRow['net_profit_label'] ?? '0.00')) ?></td>
                                <td><?= e((string) ((int) ($branchRow['pending_fx_count'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>This Month Expense by Branch</h2>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Currency</th>
                    <th>Total Expenses</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($expenseByBranch === []): ?>
                    <tr>
                        <td colspan="3">No business expenses recorded for this month.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseByBranch as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['currency'] ?? 'PKR')) ?></td>
                            <td><?= e((string) ($row['total_expenses'] ?? '0.00')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>This Month Expense by Category</h2>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Category</th>
                    <th>Currency</th>
                    <th>Total Expenses</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($expenseByCategory === []): ?>
                    <tr>
                        <td colspan="4">No expense category totals available for this month.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseByCategory as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['category_name'] ?? 'Miscellaneous')) ?></td>
                            <td><?= e((string) ($row['currency'] ?? 'PKR')) ?></td>
                            <td><?= e((string) ($row['total_expenses'] ?? '0.00')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
