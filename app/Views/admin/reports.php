<?php
/* Screen A16: Reports (/admin/reports).
   Vars: $report */
$period = $report['period'];
$tiles = $report['tiles'];
$sc = $report['status_counts'];
$exportQuery = $period === 'daily'
    ? ['period' => 'daily', 'date' => $report['date']]
    : ['period' => 'monthly', 'month' => $report['month']];
?>

<div class="no-print">
    <div class="row-between section">
        <nav class="chips" aria-label="Report period mode">
            <a class="chip<?= $period === 'monthly' ? ' is-active' : '' ?>"
               href="<?= e(url('/admin/reports', ['period' => 'monthly', 'month' => $report['month']])) ?>"
               <?= $period === 'monthly' ? ' aria-current="page"' : '' ?>>Monthly</a>
            <a class="chip<?= $period === 'daily' ? ' is-active' : '' ?>"
               href="<?= e(url('/admin/reports', ['period' => 'daily', 'date' => $report['date']])) ?>"
               <?= $period === 'daily' ? ' aria-current="page"' : '' ?>>Daily</a>
        </nav>

        <div class="row-gap">
            <button type="button" class="btn btn-outline btn-small" data-print><?= icon('print') ?> Print report</button>
            <a class="btn btn-primary btn-small" href="<?= e(url('/admin/reports/export.csv', $exportQuery)) ?>">Export CSV</a>
        </div>
    </div>

    <div class="card section">
        <div class="row-between">
            <?php if ($period === 'daily'): ?>
                <a class="btn btn-small btn-outline" href="<?= e(url('/admin/reports', ['period' => 'daily', 'date' => $report['prev_key']])) ?>" aria-label="Previous day">◀ Prev</a>
                <form method="get" action="<?= e(url('/admin/reports')) ?>" class="row-gap">
                    <input type="hidden" name="period" value="daily">
                    <input type="date" name="date" value="<?= e($report['date']) ?>" aria-label="Choose report date">
                    <button type="submit" class="btn btn-small btn-primary">Go</button>
                </form>
                <a class="btn btn-small btn-outline" href="<?= e(url('/admin/reports', ['period' => 'daily', 'date' => $report['next_key']])) ?>" aria-label="Next day">Next ▶</a>
            <?php else: ?>
                <a class="btn btn-small btn-outline" href="<?= e(url('/admin/reports', ['period' => 'monthly', 'month' => $report['prev_key']])) ?>" aria-label="Previous month">◀ Prev</a>
                <form method="get" action="<?= e(url('/admin/reports')) ?>" class="row-gap">
                    <input type="hidden" name="period" value="monthly">
                    <input type="month" name="month" value="<?= e($report['month']) ?>" aria-label="Choose report month">
                    <button type="submit" class="btn btn-small btn-primary">Go</button>
                </form>
                <a class="btn btn-small btn-outline" href="<?= e(url('/admin/reports', ['period' => 'monthly', 'month' => $report['next_key']])) ?>" aria-label="Next month">Next ▶</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<section class="card section">
    <p class="eyebrow"><?= e($report['studio_name']) ?> · <?= $period === 'daily' ? 'Daily Report' : 'Monthly Report' ?></p>
    <h2 class="card-title"><?= e($report['label']) ?></h2>
    <?php if (!empty($report['studio_address'])): ?>
        <p class="small muted"><?= e($report['studio_address']) ?></p>
    <?php endif; ?>
</section>

<section class="grid-2 section" aria-label="Report summary tiles">
    <div class="card">
        <p class="eyebrow">Bookings</p>
        <p class="h2"><?= (int) $tiles['bookings'] ?></p>
        <p class="small muted">
            <?= (int) $sc['confirmed'] ?> confirmed · <?= (int) $sc['completed'] ?> completed · <?= (int) $sc['pending'] ?> pending
        </p>
    </div>
    <div class="card">
        <p class="eyebrow">Income received</p>
        <p class="h2 money"><?= e(peso($tiles['income_received'])) ?></p>
        <p class="small muted">Verified payments for period events</p>
    </div>
    <div class="card">
        <p class="eyebrow">Outstanding balances</p>
        <p class="h2 money"><?= e(peso($tiles['outstanding_balances'])) ?></p>
        <p class="small muted">Remaining on confirmed bookings</p>
    </div>
    <div class="card">
        <p class="eyebrow">Pending outputs</p>
        <p class="h2"><?= (int) $tiles['pending_outputs'] ?></p>
        <p class="small muted">
            <?= (int) $tiles['overdue_outputs'] > 0 ? (int) $tiles['overdue_outputs'] . ' overdue' : '0 overdue' ?>
        </p>
    </div>
</section>

<section class="section" aria-labelledby="rep-bookings-heading">
    <h2 class="h3" id="rep-bookings-heading">Bookings in <?= e($report['label']) ?> (<?= count($report['bookings']) ?>)</h2>

    <?php if (empty($report['bookings'])): ?>
        <div class="empty">
            <?= icon('chart', 'icon icon-xl') ?>
            <p><strong>No bookings for <?= e($report['label']) ?>.</strong></p>
            <p class="muted">Select another month or day above.</p>
        </div>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($report['bookings'] as $b): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <div>
                            <a href="<?= e(url('/admin/bookings/' . (int) $b['id'])) ?>">
                                <strong><?= e($b['reference_code']) ?></strong>
                            </a>
                            · <strong><?= e($b['client_name']) ?></strong>
                        </div>
                        <?= partial('partials/tag', ['status' => $b['status']]) ?>
                    </div>
                    <p class="card-meta">
                        <?= e(date_long($b['event_date'])) ?>
                        <?= !empty($b['start_time']) ? ' · ' . e(time12($b['start_time'])) : '' ?>
                        · <?= e($b['event_type']) ?> (<?= e($b['package_name']) ?>)
                    </p>
                    <div class="card-row">
                        <span>Total</span>
                        <strong class="money"><?= e(peso($b['total_amount'])) ?></strong>
                    </div>
                    <div class="card-row">
                        <span>Verified paid</span>
                        <strong class="money"><?= e(peso($b['verified_paid'])) ?></strong>
                    </div>
                    <div class="card-row">
                        <span>Outstanding balance</span>
                        <strong class="money"><?= e(peso($b['balance_due'])) ?></strong>
                    </div>
                    <?php if (!empty($b['project_stage'])): ?>
                        <div class="card-row">
                            <span>Project stage</span>
                            <span>
                                <?= e(ucfirst((string) $b['project_stage'])) ?>
                                <?php if (!empty($b['project_due_date'])): ?>
                                    <span class="small muted">(Due <?= e(date_short($b['project_due_date'])) ?>)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($report['payments'])): ?>
    <section class="card stack section" aria-labelledby="rep-pay-heading">
        <h2 class="card-title" id="rep-pay-heading">Verified receipts (<?= count($report['payments']) ?>)</h2>
        <div class="stack-s">
            <?php foreach ($report['payments'] as $py): ?>
                <div class="card-row">
                    <div>
                        <strong><?= e($py['receipt_no'] ?: 'Verified') ?></strong>
                        · <?= e($py['client_name']) ?> (<?= e($py['reference_code']) ?>)
                        <p class="small muted">
                            <?= e(ucfirst((string) $py['type'])) ?> via <?= e(strtoupper((string) $py['method'])) ?>
                            · Event <?= e(date_short($py['event_date'])) ?>
                        </p>
                    </div>
                    <strong class="money"><?= e(peso($py['amount'])) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
