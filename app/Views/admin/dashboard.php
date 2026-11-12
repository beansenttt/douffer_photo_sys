<?php
/* Screen A2: Dashboard. Live summary tiles, "Needs attention" studio rules, and backup status.
   Vars: $user, $today, $mustChange, $metrics, $alerts, $backupStatus */
?>
<p class="eyebrow"><?= e(date_long($today)) ?><?= \App\Core\Clock::isDemo() ? ' · demo date' : '' ?></p>
<h2 class="h2">Hello, <?= e(explode(' ', (string) ($user['name'] ?? 'Admin'))[0]) ?></h2>

<?php if ($mustChange): ?>
    <div class="alert alert-warning" role="alert">
        <?= icon('alert') ?>
        <div>
            <strong>Change the default password.</strong>
            <p>This account still uses the password from the setup guide.</p>
            <a class="btn btn-small" href="<?= e(url('/account/password')) ?>">Change password</a>
        </div>
    </div>
<?php endif; ?>

<div class="tiles">
    <a class="tile" href="<?= e(url('/admin/calendar', ['date' => $today])) ?>">
        <?= icon('calendar') ?>
        <span class="tile-value"><?= (int) ($metrics['events_today'] ?? 0) ?></span>
        <span class="tile-label">Events today</span>
    </a>
    <a class="tile<?= (!empty($metrics['payments_to_verify']) ? ' tile-alert' : '') ?>" href="<?= e(url('/admin/payments')) ?>">
        <?= icon('wallet') ?>
        <span class="tile-value"><?= (int) ($metrics['payments_to_verify'] ?? 0) ?></span>
        <span class="tile-label">Payments to verify</span>
    </a>
    <a class="tile<?= (!empty($metrics['balances_due']) ? ' tile-alert' : '') ?>" href="<?= e(url('/admin/bookings', ['filter' => 'balance_due'])) ?>">
        <?= icon('list') ?>
        <span class="tile-value"><?= (int) ($metrics['balances_due'] ?? 0) ?></span>
        <span class="tile-label">Balances due</span>
    </a>
    <a class="tile<?= (!empty($metrics['outputs_overdue']) ? ' tile-alert' : '') ?>" href="<?= e(url('/admin/projects')) ?>">
        <?= icon('folder') ?>
        <span class="tile-value"><?= (int) ($metrics['outputs_overdue'] ?? 0) ?></span>
        <span class="tile-label">Outputs overdue</span>
    </a>
</div>

<a class="btn btn-primary btn-block mb-1" href="<?= e(url('/admin/bookings/new')) ?>"><?= icon('plus') ?> New booking</a>

<section class="section" aria-label="Needs attention">
    <h3 class="h3">Needs attention</h3>
    <?php if (empty($alerts)): ?>
        <div class="empty">
            <?= icon('check', 'icon-xl') ?>
            <p class="mb-0"><strong>Nothing needs attention today ✓</strong></p>
            <p class="small muted mb-0">All events are staffed, deliverables are on track, and payments are up to date.</p>
        </div>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($alerts as $alert): ?>
                <div class="card">
                    <div class="row-between">
                        <span class="tag tag-<?= e($alert['badge_class']) ?>"><?= e($alert['badge']) ?></span>
                        <?php if (!empty($alert['meta'])): ?>
                            <span class="card-meta small"><?= e($alert['meta']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h4 class="card-title mt-0"><?= e($alert['title']) ?></h4>
                    <p class="card-meta mb-0"><?= e($alert['message']) ?></p>
                    <?php if (!empty($alert['url'])): ?>
                        <div class="mt-1">
                            <a class="btn btn-small btn-outline" href="<?= e(url($alert['url'])) ?>">View details &rarr;</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section" aria-label="Studio backup status">
    <div class="card">
        <div class="row-between">
            <div class="row">
                <?= icon('shield') ?>
                <div>
                    <strong>Studio Backup</strong>
                    <p class="small muted mb-0">
                        <?= e($backupStatus['status_line']) ?>
                    </p>
                </div>
            </div>
            <div>
                <?php if ($backupStatus['is_stale']): ?>
                    <span class="tag tag-overdue">Backup overdue ⚠</span>
                <?php else: ?>
                    <span class="tag tag-ok">Current ✓</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (\App\Core\Auth::isOwner()): ?>
            <div class="mt-1">
                <a class="btn btn-small btn-outline" href="<?= e(url('/admin/settings')) ?>">Manage backups &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</section>
