<?php
/* Screen A3: Booking Calendar. Month grid with capacity indicators and day schedule inspection.
   Vars: $user, $today, $year, $month, $selectedDate, $monthData, $daySchedule,
         $prevYear, $prevMonth, $nextYear, $nextMonth */

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$offset = (int) date('w', strtotime($firstDay)); // 0 = Sunday
$monthLabel = date('F Y', strtotime($firstDay));
?>
<div class="row-between mb-1">
    <div>
        <p class="eyebrow mb-0">Screen A3 · Calendar</p>
        <h2 class="h2 mb-0"><?= e($monthLabel) ?></h2>
    </div>
    <div class="row">
        <a class="btn btn-small btn-outline" href="<?= e(url('/admin/calendar', ['date' => $today])) ?>">Today</a>
        <a class="btn btn-small btn-primary" href="<?= e(url('/admin/bookings/new')) ?>"><?= icon('plus') ?> New booking</a>
    </div>
</div>

<section class="cal" aria-label="Booking Calendar for <?= e($monthLabel) ?>">
    <div class="cal-head">
        <a class="topbar-btn cal-nav" href="<?= e(url('/admin/calendar', ['year' => $prevYear, 'month' => $prevMonth, 'date' => $selectedDate])) ?>" aria-label="Previous month"><?= icon('back') ?></a>
        <h3 class="h3 mb-0"><?= e($monthLabel) ?></h3>
        <a class="topbar-btn cal-nav" href="<?= e(url('/admin/calendar', ['year' => $nextYear, 'month' => $nextMonth, 'date' => $selectedDate])) ?>" aria-label="Next month"><?= icon('next') ?></a>
    </div>

    <div class="cal-grid" role="grid">
        <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dow): ?>
            <div class="cal-dow" aria-hidden="true"><?= $dow ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $offset; $i++): ?>
            <span class="cal-day is-off" aria-hidden="true"></span>
        <?php endfor; ?>

        <?php foreach ($monthData as $dStr => $day):
            $classes = ['cal-day'];
            if ($day['is_blocked']) {
                $classes[] = 'is-unavailable';
            } elseif ($day['is_full']) {
                $classes[] = 'is-full';
            } elseif ($day['has_pending_only']) {
                $classes[] = 'is-pending';
            }
            if ($day['is_today']) {
                $classes[] = 'is-today';
            }
            if ($dStr === $selectedDate) {
                $classes[] = 'is-selected';
            }

            $aria = date('D, M j', strtotime($dStr))
                . ($day['is_blocked'] ? ', Blocked: ' . ($day['blocked_reason'] ?? 'Closed') : '')
                . ($day['is_full'] ? ', Full capacity' : '')
                . ($day['confirmed_count'] > 0 ? ', ' . $day['confirmed_count'] . ' confirmed' : '')
                . ($day['pending_count'] > 0 ? ', ' . $day['pending_count'] . ' pending' : '')
                . ($dStr === $selectedDate ? ', Selected' : '');
        ?>
            <a class="<?= e(implode(' ', $classes)) ?>"
               href="<?= e(url('/admin/calendar', ['year' => $year, 'month' => $month, 'date' => $dStr])) ?>"
               aria-label="<?= e($aria) ?>"<?= $dStr === $selectedDate ? ' aria-current="date"' : '' ?>>
                <span><?= (int) $day['day'] ?></span>
                <?php if ($day['confirmed_count'] > 0): ?>
                    <span class="cal-dots" aria-hidden="true">
                        <?php for ($i = 0; $i < min(2, $day['confirmed_count']); $i++): ?>
                            <span class="cal-dot"></span>
                        <?php endfor; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="legend" aria-hidden="true">
        <span><i class="swatch"></i> Open</span>
        <span><span class="cal-dot"></span> Confirmed (dot)</span>
        <span><span class="tag tag-rejected">2+ events (full)</span></span>
        <span><span class="tag tag-pending">Pending only</span></span>
        <span><i class="swatch swatch-off"></i> Blocked</span>
    </div>
</section>

<section class="section" aria-label="Day schedule inspection">
    <div class="card mb-1">
        <div class="row-between">
            <div>
                <p class="eyebrow mb-0">Schedule for</p>
                <h3 class="h3 mb-0"><?= e($daySchedule['date_formatted']) ?></h3>
            </div>
            <div>
                <?php if ($daySchedule['is_blocked']): ?>
                    <span class="tag tag-rejected">Blocked: <?= e($daySchedule['blocked_reason'] ?? 'Closed') ?></span>
                <?php elseif ($daySchedule['is_full']): ?>
                    <span class="tag tag-overdue">Full capacity (<?= (int) $daySchedule['confirmed_count'] ?>/2 booked)</span>
                <?php else: ?>
                    <span class="tag tag-ok"><?= (int) $daySchedule['confirmed_count'] ?>/2 booked · Open</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($daySchedule['has_crew_shortage'])): ?>
            <div class="alert alert-warning mt-1 mb-0" role="alert">
                <?= icon('alert') ?>
                <div>
                    <strong>Crew Shortage:</strong>
                    <p class="mb-0"><?= e($daySchedule['crew_shortage_message'] ?? 'One or more events lack assigned crew.') ?></p>
                    <div class="mt-1">
                        <a class="btn btn-small" href="<?= e(url('/admin/crew', ['date' => $selectedDate])) ?>">Manage Crew Assignment &rarr;</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($daySchedule['can_add_booking']): ?>
            <div class="mt-1">
                <a class="btn btn-primary btn-block btn-small" href="<?= e(url('/admin/bookings/new', ['date' => $selectedDate])) ?>">
                    <?= icon('plus') ?> Add booking on this date
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($daySchedule['events'])): ?>
        <div class="empty">
            <p class="mb-0">No bookings scheduled on this date.</p>
            <?php if ($daySchedule['can_add_booking']): ?>
                <div class="mt-1">
                    <a class="btn btn-outline btn-small" href="<?= e(url('/admin/bookings/new', ['date' => $selectedDate])) ?>">
                        <?= icon('plus') ?> Book this date
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($daySchedule['events'] as $ev): ?>
                <div class="card">
                    <div class="row-between">
                        <div>
                            <span class="tag tag-<?= e($ev['status']) ?>"><?= ucfirst(e($ev['status'])) ?></span>
                            <span class="small muted"><?= e($ev['reference_code']) ?></span>
                        </div>
                        <span class="small money"><?= e(peso($ev['total_amount'])) ?></span>
                    </div>
                    <h4 class="card-title mt-0"><?= e($ev['client_name']) ?> — <?= e($ev['event_type']) ?></h4>
                    <div class="card-meta">
                        <p class="mb-0"><strong>Package:</strong> <?= e($ev['package_name']) ?></p>
                        <p class="mb-0"><strong>Time:</strong> <?= e($ev['time_formatted']) ?></p>
                        <p class="mb-0"><strong>Venue:</strong> <?= e($ev['venue']) ?></p>
                        <p class="mb-0"><strong>Balance:</strong> <?= e(peso($ev['balance'])) ?></p>
                        <?php if (!empty($ev['project_stage'])): ?>
                            <p class="mb-0"><strong>Project:</strong> <?= ucfirst(e($ev['project_stage'])) ?><?= $ev['is_overdue'] ? ' <span class="tag tag-overdue">Overdue</span>' : '' ?></p>
                        <?php endif; ?>
                        <p class="mb-0">
                            <strong>Crew:</strong>
                            <?php if (!empty($ev['crew'])): ?>
                                <?= e(implode(', ', array_map(fn($c) => $c['crew_name'] . ' (' . $c['role'] . ')', $ev['crew']))) ?>
                            <?php else: ?>
                                <span class="tag tag-conflict">No crew assigned ⚠</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="row mt-1">
                        <a class="btn btn-outline btn-small" href="<?= e(url('/admin/bookings/' . $ev['id'])) ?>">View Booking &rarr;</a>
                        <a class="btn btn-ghost btn-small" href="<?= e(url('/admin/crew', ['date' => $selectedDate])) ?>">Manage Crew</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
