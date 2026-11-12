<?php
/**
 * Screen A13: Reschedule or Cancel Booking.
 * Strict CSP compliant: no inline styles or scripts.
 */
declare(strict_types=1);

use App\Core\Clock;

$bid = (int) $booking['id'];
$status = (string) $booking['status'];
$today = Clock::today();
?>
<p class="eyebrow">Screen A13 · Reschedule or Cancel</p>

<!-- Booking Summary Card -->
<article class="card">
    <div class="card-row">
        <div>
            <h2 class="card-title"><?= e($booking['full_name']) ?> – <?= e($booking['event_type']) ?></h2>
            <div class="card-meta">
                Current date: <strong><?= e(date_long($booking['event_date'])) ?></strong>
                <?php if (!empty($booking['start_time'])): ?>
                    · <?= e(time12($booking['start_time'])) ?><?= !empty($booking['end_time']) ? ' – ' . e(time12($booking['end_time'])) : '' ?>
                <?php endif; ?>
            </div>
            <div class="card-meta">Venue: <?= e($booking['venue']) ?> · Ref. <strong><?= e($booking['reference_code']) ?></strong></div>
        </div>
        <div class="stack-right">
            <?= partial('partials/tag', ['class' => $status === 'confirmed' ? 'confirmed' : 'pending', 'label' => ucfirst($status)]) ?>
        </div>
    </div>
    <div class="card-row mt-1">
        <span class="muted">Total verified paid to date</span>
        <strong class="money"><?= e(peso($paid)) ?></strong>
    </div>
</article>

<!-- Mode Toggle Chips -->
<nav class="chips mt-1" aria-label="Change action selection">
    <button type="button" class="chip<?= $mode === 'reschedule' ? ' is-active' : '' ?>" data-change-mode="reschedule" aria-pressed="<?= $mode === 'reschedule' ? 'true' : 'false' ?>">
        Reschedule date
    </button>
    <button type="button" class="chip<?= $mode === 'cancel' ? ' is-active' : '' ?>" data-change-mode="cancel" aria-pressed="<?= $mode === 'cancel' ? 'true' : 'false' ?>">
        Cancel booking
    </button>
</nav>

<!-- Reschedule Panel -->
<section id="panel-reschedule" class="mt-1"<?= $mode === 'reschedule' ? '' : ' hidden' ?>>
    <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/change')) ?>" class="card stack" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reschedule">

        <h3 class="card-title">Reschedule to an open date</h3>
        <p class="card-meta">Rescheduling is free if the new date is open (BR-3). History timeline and chat notice will be updated automatically.</p>

        <div class="field">
            <label for="reschedule-date">Event date <span class="req">*</span></label>
            <input type="date" id="reschedule-date" name="new_date" min="<?= e($today) ?>" value="<?= e(old('new_date', $booking['event_date'])) ?>" required data-availability-check data-exclude="<?= $bid ?>" data-target="reschedule-avail-banner"<?= invalid_attr('new_date') ?>>
            <div id="reschedule-avail-banner" class="mt-1" aria-live="polite"></div>
            <p class="hint">Keep current date if you only need to adjust event hours or venue.</p>
            <?= error_html('new_date') ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="reschedule-start">Start time</label>
                <input type="time" id="reschedule-start" name="start_time" value="<?= e(old('start_time', $booking['start_time'] ? substr($booking['start_time'], 0, 5) : '')) ?>"<?= invalid_attr('start_time') ?>>
                <?= error_html('start_time') ?>
            </div>
            <div class="field">
                <label for="reschedule-end">End time</label>
                <input type="time" id="reschedule-end" name="end_time" value="<?= e(old('end_time', $booking['end_time'] ? substr($booking['end_time'], 0, 5) : '')) ?>"<?= invalid_attr('end_time') ?>>
                <?= error_html('end_time') ?>
            </div>
        </div>

        <div class="field">
            <label for="reschedule-venue">Venue</label>
            <input type="text" id="reschedule-venue" name="venue" value="<?= e(old('venue', $booking['venue'])) ?>" maxlength="255"<?= invalid_attr('venue') ?>>
            <p class="hint">Leave unchanged or update to new venue address.</p>
            <?= error_html('venue') ?>
        </div>

        <div class="field">
            <label for="reschedule-reason">Reason for date or schedule adjustment <span class="req">*</span></label>
            <textarea id="reschedule-reason" name="reason" rows="2" required placeholder="e.g. Client requested date shift or venue/time change..."><?= e(old('reason')) ?></textarea>
            <?= error_html('reason') ?>
        </div>

        <div class="mt-1">
            <button type="submit" id="btn-submit-reschedule" class="btn btn-primary btn-block btn-lg"><?= icon('check') ?> Confirm reschedule</button>
            <a class="btn btn-block mt-1" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Back to booking hub</a>
        </div>
    </form>
</section>

<!-- Cancel Panel -->
<section id="panel-cancel" class="mt-1"<?= $mode === 'cancel' ? '' : ' hidden' ?>>
    <div class="card stack">
        <h3 class="card-title">Cancel this booking</h3>

        <div class="alert alert-warning">
            <?= icon('alert') ?>
            <div>
                <strong>Non-refundable down payment policy (BR-2)</strong>
                <p class="mb-0">
                    Verified down payment of <strong><?= e(peso($verifiedDownAmount)) ?></strong> is forfeited. No refund is offered.
                    The event date (<strong><?= e(date_short($booking['event_date'])) ?></strong>) will be freed immediately for other bookings.
                </p>
            </div>
        </div>

        <?php if ($canCancel): ?>
            <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/change')) ?>" data-once>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">

                <div class="field">
                    <label for="cancel-reason">Cancellation reason <span class="req">*</span></label>
                    <textarea id="cancel-reason" name="reason" rows="3" required placeholder="e.g. Client cancelled due to unforeseen personal circumstances..."<?= invalid_attr('reason') ?>><?= e(old('reason')) ?></textarea>
                    <?= error_html('reason') ?>
                </div>

                <div class="field mt-1">
                    <label class="check">
                        <input type="checkbox" name="confirm_forfeit" value="1" required<?= invalid_attr('confirm_forfeit') ?>>
                        <span>I confirm that the client understands the down payment is forfeited without refund.</span>
                    </label>
                    <?= error_html('confirm_forfeit') ?>
                </div>

                <div class="mt-1">
                    <button type="submit" class="btn btn-danger btn-block btn-lg"><?= icon('alert') ?> Cancel booking &amp; forfeit payment</button>
                    <a class="btn btn-block mt-1" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Back to booking hub</a>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning mt-1">
                <?= icon('shield') ?>
                <span>Only the studio owner or staff with <strong>archive_records</strong> permission can cancel bookings.</span>
            </div>
            <a class="btn btn-block mt-1" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Back to booking hub</a>
        <?php endif; ?>
    </div>
</section>
