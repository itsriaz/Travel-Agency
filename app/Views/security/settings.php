<?php
use App\Helpers\Auth;
$user = $summary['user'];
$devices = $summary['trusted_devices'];
$failures = $summary['failure_summary'];
$events = $summary['events'];
?>
<section class="page-head">
    <div>
        <h1>Security Settings</h1>
    </div>
</section>

<section class="stat-grid security-stat-grid">
    <article class="stat-card">
        <div class="stat-label">Password Changed</div>
        <div class="stat-value stat-value-sm"><?= e((string) ($user['password_changed_at'] ?? 'Never')) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">2FA Status</div>
        <div class="stat-value stat-value-sm"><?= (int) ($user['two_factor_enabled'] ?? 0) === 1 ? 'Enabled' : 'Not Enabled' ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Last Successful Login</div>
        <div class="stat-value stat-value-sm"><?= e((string) ($user['last_login_at'] ?? 'Never')) ?></div>
    </article>
</section>

<section class="workspace-grid security-grid">
    <div class="panel compact-panel">
        <div class="panel-header">
            <h2>Security Actions</h2>
        </div>
        <div class="security-action-list">
            <form method="post" action="<?= e(url('/security/2fa/re-enroll')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <button class="btn btn-primary" type="submit">Re-enroll 2FA</button>
            </form>
            <form method="post" action="<?= e(url('/security/backup-codes/regenerate')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <button class="btn" type="submit">Regenerate Backup Codes</button>
            </form>
            <form method="post" action="<?= e(url('/security/logout-all-devices')) ?>" class="compact-inline-form">
                <?= \App\Helpers\Csrf::input() ?>
                <button class="btn btn-danger" type="submit">Logout All Devices</button>
            </form>
            <?php if (Auth::isSuperAdmin()): ?>
                <a class="btn" href="<?= e(url('/admin/security')) ?>">Super Admin Controls</a>
            <?php endif; ?>
        </div>

        <div class="panel-header top-gap">
            <h2>Recent Failed Events</h2>
        </div>
        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($failures === []): ?>
                        <tr><td colspan="2" class="empty-cell">No recent failed security events.</td></tr>
                    <?php else: ?>
                        <?php foreach ($failures as $failure): ?>
                            <tr>
                                <td><?= e($failure['event_name']) ?></td>
                                <td><?= e((string) $failure['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel compact-panel">
        <div class="panel-header">
            <h2>Trusted Devices</h2>
        </div>
        <?php if (Auth::isSuperAdmin()): ?>
            <div class="placeholder-card">Trusted devices are intentionally disabled for super admin accounts.</div>
        <?php else: ?>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Last Used</th>
                            <th>Expires</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($devices === []): ?>
                            <tr><td colspan="4" class="empty-cell">No trusted devices registered.</td></tr>
                        <?php else: ?>
                            <?php foreach ($devices as $device): ?>
                                <tr>
                                    <td><?= e($device['device_label']) ?></td>
                                    <td><?= e((string) ($device['last_used_at'] ?? 'Never')) ?></td>
                                    <td><?= e((string) $device['expires_at']) ?></td>
                                    <td>
                                        <form method="post" action="<?= e(url('/security/trusted-devices/revoke')) ?>">
                                            <?= \App\Helpers\Csrf::input() ?>
                                            <input type="hidden" name="device_id" value="<?= e((string) $device['id']) ?>">
                                            <button class="btn btn-sm btn-danger" type="submit">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="panel-header top-gap">
            <h2>Security Events</h2>
        </div>
        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>IP</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($events === []): ?>
                        <tr><td colspan="3" class="empty-cell">No recent security events.</td></tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= e($event['event_name']) ?></td>
                                <td><?= e((string) ($event['ip_address'] ?? '-')) ?></td>
                                <td><?= e((string) $event['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
