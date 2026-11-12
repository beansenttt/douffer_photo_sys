<?php
/**
 * Screen A9: Crew & Equipment Assignment (/admin/crew?date=YYYY-MM-DD).
 * Strict CSP: zero inline scripts, zero inline styles, zero onclick/onchange attributes.
 * Mobile-first: 360px viewport width without horizontal scroll.
 */
use App\Core\Auth;
use App\Core\Clock;

$canManage = Auth::isOwner() || Auth::can('view_bookings');
$eventCount = count($events);
?>
<p class="eyebrow">Screen A9 · Crew &amp; Equipment Assignment</p>

<!-- Date Navigator Card -->
<section class="card mb-1" aria-label="Date selector">
    <div class="row-between">
        <div>
            <h2 class="card-title mb-0"><?= e(date_long($date)) ?></h2>
            <div class="card-meta">
                <?= $eventCount ?> <?= $eventCount === 1 ? 'event' : 'events' ?> scheduled
                <?php if ($date === Clock::today()): ?>
                    · <span class="tag tag-info">Today</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="row">
            <a class="btn btn-outline btn-small" href="<?= e(url('/admin/crew?date=' . $prev_date)) ?>" aria-label="Previous day"><?= icon('back') ?></a>
            <a class="btn btn-outline btn-small" href="<?= e(url('/admin/crew?date=' . Clock::today())) ?>">Today</a>
            <a class="btn btn-outline btn-small" href="<?= e(url('/admin/crew?date=' . $next_date)) ?>" aria-label="Next day"><?= icon('next') ?></a>
        </div>
    </div>

    <form method="get" action="<?= e(url('/admin/crew')) ?>" class="mt-1">
        <div class="input-group">
            <label for="crew-date-jump" class="sr-only">Jump to date</label>
            <input type="date" id="crew-date-jump" name="date" value="<?= e($date) ?>" required>
            <button type="submit" class="btn btn-primary input-addon">Go</button>
        </div>
    </form>
</section>

<!-- Scenario T4 Shortage / All Clear Banner -->
<?php if ($eventCount > 0): ?>
    <?php if ($has_shortage): ?>
        <div class="alert alert-danger" role="alert">
            <?= icon('alert') ?>
            <div>
                <strong>Crew Shortage Detected</strong>
                <p class="mb-0"><?= e($shortage_message ?? 'One or more events on this date have no assigned crew. Assign an on-call photographer to ensure coverage.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-success" role="status">
            <?= icon('check') ?>
            <div>
                <strong>All Events Covered</strong>
                <p class="mb-0">All events on this date have assigned crew.</p>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="empty">
        <?= icon('calendar', 'icon icon-xl') ?>
        <p><strong>No events scheduled on this date.</strong></p>
        <p class="muted">There are no confirmed or pending bookings on <?= e(date_long($date)) ?>.</p>
        <div class="mt-1">
            <a class="btn btn-outline" href="<?= e(url('/admin/bookings/new?date=' . $date)) ?>">+ Add booking on this date</a>
        </div>
    </div>
<?php endif; ?>

<!-- Scheduled Events on this Date -->
<?php foreach ($events as $event):
    $bid = (int) $event['id'];
    $assignedCrew = $event['crew'] ?? [];
    $isShort = empty($assignedCrew);
    $statusClass = $event['status'] === 'confirmed' ? 'confirmed' : 'pending';
?>
    <article class="card mt-1" aria-label="Event <?= e($event['reference_code']) ?>">
        <div class="card-row">
            <div>
                <h3 class="card-title">
                    <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid)) ?>"><?= e($event['client_name'] ?? $event['full_name']) ?></a>
                    – <?= e($event['event_type']) ?>
                </h3>
                <div class="card-meta">
                    <strong><?= e(time12($event['start_time'])) ?> – <?= e(time12($event['end_time'])) ?></strong>
                    · Package: <?= e($event['package_name'] ?? 'Custom') ?>
                </div>
                <div class="card-meta">Venue: <?= e($event['venue']) ?> · Ref: <strong><?= e($event['reference_code']) ?></strong></div>
            </div>
            <div class="stack-right">
                <?= partial('partials/tag', ['class' => $statusClass, 'label' => ucfirst($event['status'])]) ?>
                <?php if ($isShort): ?>
                    <?= partial('partials/tag', ['class' => 'conflict', 'label' => 'No Crew']) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assigned Crew List -->
        <div class="section mt-1">
            <h4 class="card-title">Assigned Crew (<?= count($assignedCrew) ?>)</h4>
            <?php if (empty($assignedCrew)): ?>
                <p class="muted small mb-0">No crew assigned yet. Use the form below to assign an on-call crew member.</p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($assignedCrew as $c): ?>
                        <div class="card-row">
                            <div>
                                <strong><?= e($c['name']) ?></strong> (<?= e(ucfirst($c['type'])) ?>)
                                <?= partial('partials/tag', ['class' => $c['role'] === 'lead' ? 'ok' : 'info', 'label' => ucfirst($c['role'])]) ?>
                                <?php if (!empty($c['is_same_day_multievent'])): ?>
                                    <?= partial('partials/tag', ['class' => 'balance', 'label' => 'Same-day multi-event']) ?>
                                <?php endif; ?>
                                <div class="small muted">
                                    Call time: <strong><?= !empty($c['call_time']) ? e(time12($c['call_time'])) : 'Default (1h prior)' ?></strong>
                                    <?php if (!empty($c['phone'])): ?> · Tel: <?= e(format_mobile($c['phone'])) ?><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= e(url('/admin/crew/unassign')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="booking_id" value="<?= $bid ?>">
                                    <input type="hidden" name="crew_member_id" value="<?= (int) $c['crew_member_id'] ?>">
                                    <input type="hidden" name="date" value="<?= e($date) ?>">
                                    <button type="submit" class="btn btn-outline btn-small btn-danger" aria-label="Remove <?= e($c['name']) ?>">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assign Crew Form -->
        <?php if ($canManage): ?>
            <div class="section mt-1">
                <h4 class="card-title">Assign Crew Member</h4>
                <form method="post" action="<?= e(url('/admin/crew/assign')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $bid ?>">
                    <input type="hidden" name="date" value="<?= e($date) ?>">

                    <div class="field">
                        <label for="crew-select-<?= $bid ?>">Crew member <span class="req">*</span></label>
                        <select id="crew-select-<?= $bid ?>" name="crew_member_id" required>
                            <option value="">Select crew member...</option>
                            <?php foreach ($crew_roster as $cm): ?>
                                <option value="<?= (int) $cm['id'] ?>">
                                    <?= e($cm['name']) ?> (<?= e(ucfirst($cm['type'])) ?>)
                                    — <?= $cm['status_on_date'] === 'free' ? 'Free' : 'Busy (' . e($cm['busy_event_ref'] ?? 'Assigned') . ')' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field mt-1">
                        <label for="role-select-<?= $bid ?>">Role <span class="req">*</span></label>
                        <select id="role-select-<?= $bid ?>" name="role">
                            <option value="lead">Lead</option>
                            <option value="backup">Backup</option>
                        </select>
                    </div>

                    <div class="field mt-1">
                        <label for="call-time-<?= $bid ?>">Call time (optional, defaults to 1h prior)</label>
                        <input type="time" id="call-time-<?= $bid ?>" name="call_time">
                    </div>

                    <div class="mt-1">
                        <button type="submit" class="btn btn-primary btn-small"><?= icon('plus') ?> Assign to event</button>
                    </div>
                </form>
            </div>

            <!-- Equipment Checklist -->
            <div class="section mt-1">
                <form method="post" action="<?= e(url('/admin/crew/equipment')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $bid ?>">
                    <input type="hidden" name="date" value="<?= e($date) ?>">

                    <fieldset>
                        <legend class="card-title">Equipment Checklist</legend>
                        <div class="stack">
                            <?php
                            $checkedMap = $event['equipment_checked_ids'] ?? [];
                            foreach ($equipment_items as $eq):
                                $isChecked = in_array((int) $eq['id'], $checkedMap, true);
                            ?>
                                <label class="check">
                                    <input type="checkbox" name="equipment_ids[]" value="<?= (int) $eq['id'] ?>"<?= $isChecked ? ' checked' : '' ?>>
                                    <span>
                                        <strong><?= e($eq['name']) ?></strong>
                                        <span class="muted small">(<?= e($eq['category'] ?? 'General') ?>)</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="mt-1">
                        <button type="submit" class="btn btn-outline btn-small"><?= icon('check') ?> Save equipment checklist</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </article>
<?php endforeach; ?>

<!-- On-Call Roster Summary Card -->
<section class="card mt-1" aria-label="On-call crew roster">
    <h3 class="card-title">Studio Crew Roster for <?= e(date_short($date)) ?></h3>
    <p class="card-meta">Overview of all active studio crew and availability for this date.</p>
    <div class="stack mt-1">
        <?php foreach ($crew_roster as $cm): ?>
            <div class="card-row">
                <div>
                    <strong><?= e($cm['name']) ?></strong>
                    <span class="muted small">· <?= e(ucfirst($cm['type'])) ?></span>
                    <?php if (!empty($cm['phone'])): ?>
                        <div class="small muted">Tel: <?= e(format_mobile($cm['phone'])) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($cm['status_on_date'] === 'free'): ?>
                        <span class="tag tag-free">Free</span>
                    <?php else: ?>
                        <span class="tag tag-busy">Busy</span>
                    <?php endif; ?>
                    <?php if (!empty($cm['is_on_call'])): ?>
                        <span class="tag tag-info">On-call</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
