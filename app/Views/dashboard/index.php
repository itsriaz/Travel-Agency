<?php

declare(strict_types=1);

$analytics = $dashboardAnalytics ?? ['periods' => [], 'expenseByBranch' => [], 'expenseByCategory' => []];
$periods = $analytics['periods'] ?? [];
$expenseByBranch = $analytics['expenseByBranch'] ?? [];
$expenseByCategory = $analytics['expenseByCategory'] ?? [];
?>

<section class="page-head">
    <div>
        <h1>Super Admin Dashboard</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/workspace')) ?>">Open Booking Workspace</a>
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
        <div class="stat-value">Gross - Expenses</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Expense Control</div>
        <div class="stat-value">Admin Only</div>
    </article>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Net Profit Summary</h2>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Gross Profit</th>
                    <th>Total Expenses</th>
                    <th>Net Profit</th>
                    <th>PKR Gross</th>
                    <th>PKR Expenses</th>
                    <th>PKR Net</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($periods === []): ?>
                    <tr>
                        <td colspan="7">No dashboard analytics available yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($periods as $period): ?>
                        <tr>
                            <td><?= e((string) ($period['label'] ?? 'Period')) ?></td>
                            <td><?= e((string) ($period['gross_profit'] ?? 'PKR 0.00')) ?></td>
                            <td><?= e((string) ($period['total_expenses'] ?? 'PKR 0.00')) ?></td>
                            <td><?= e((string) ($period['net_profit'] ?? 'PKR 0.00')) ?></td>
                            <td><?= e((string) ($period['pkr_gross_profit'] ?? 'PKR 0.00')) ?></td>
                            <td><?= e((string) ($period['pkr_total_expenses'] ?? 'PKR 0.00')) ?></td>
                            <td><?= e((string) ($period['pkr_net_profit'] ?? 'PKR 0.00')) ?></td>
                        </tr>
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
                    <th>PKR Converted</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($expenseByBranch === []): ?>
                    <tr>
                        <td colspan="4">No business expenses recorded for this month.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseByBranch as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['currency'] ?? 'PKR')) ?></td>
                            <td><?= e((string) ($row['total_expenses'] ?? '0.00')) ?></td>
                            <td><?= e((string) ($row['pkr_total_expenses'] ?? 'N/A')) ?></td>
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
                    <th>PKR Converted</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($expenseByCategory === []): ?>
                    <tr>
                        <td colspan="5">No expense category totals available for this month.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseByCategory as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['category_name'] ?? 'Miscellaneous')) ?></td>
                            <td><?= e((string) ($row['currency'] ?? 'PKR')) ?></td>
                            <td><?= e((string) ($row['total_expenses'] ?? '0.00')) ?></td>
                            <td><?= e((string) ($row['pkr_total_expenses'] ?? 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
