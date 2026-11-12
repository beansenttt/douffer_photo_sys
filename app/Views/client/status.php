<?php
/* Screen C5: Booking Status. Vars: $booking $payment $paid $balance $project $step $rejected */
$status = $booking['status'];
$confirmed = in_array($status, ['confirmed', 'completed'], true);
$cancelled = $status === 'cancelled';
$stageLabels = ['booked' => 'Booked', 'covered' => 'Covered', 'editing' => 'Editing', 'ready' => 'Ready', 'released' => 'Released'];

if ($cancelled) {
    $next = 'This booking was cancelled. The down payment is non-refundable. Please message the studio if you have questions.';
} elseif ($confirmed) {
    $next = 'Your date is reserved. Pay any balance on or before the event date. We will message you when your photos and videos are ready.';
} elseif ($rejected) {
    $next = 'We could not accept your payment screenshot. Please send a new one below.';
} else {
    $next = 'The studio is checking your GCash payment. Your date is reserved once it is confirmed. This usually takes a few hours.';
}
?>
<section class="status-hero <?= $cancelled ? 'is-bad' : '' ?>">
    <span class="bigcheck" aria-hidden="true"><?= icon($cancelled ? 'close' : 'check', 'icon icon-xl') ?></span>
    <h2 class="h2"><?= $cancelled ? 'Booking cancelled' : ($confirmed ? 'Booking confirmed' : 'Request sent') ?></h2>
    <p class="muted mb-0">Reference no.</p>
    <p class="refcode" id="refcode"><?= e($booking['reference_code']) ?></p>
    <button type="button" class="btn btn-outline btn-small" data-copy="refcode"><?= icon('check') ?> Copy number</button>
</section>

<?php if (!$cancelled): ?>
<ol class="steps steps-3" aria-label="Booking progress">
    <li class="done"><span><?= icon('check', 'icon icon-s') ?></span> Request sent</li>
    <li class="<?= $confirmed ? 'done' : '' ?>"<?= !$confirmed ? ' aria-current="step"' : '' ?>><span><?= $confirmed ? icon('check', 'icon icon-s') : '2' ?></span> Payment being checked</li>
    <li class="<?= $confirmed ? 'done' : '' ?>"<?= $confirmed ? ' aria-current="step"' : '' ?>><span><?= $confirmed ? icon('check', 'icon icon-s') : '3' ?></span> Booking confirmed</li>
</ol>
<?php endif; ?>

<p class="next-step"><?= e($next) ?></p>

<?php if ($rejected): ?>
<section class="card reject-box" id="reupload" aria-labelledby="rej-title">
    <h2 class="card-title" id="rej-title"><?= partial('partials/tag', ['class' => 'rejected', 'label' => 'Payment rejected']) ?></h2>
    <p><strong>Reason:</strong> <?= e($payment['rejection_reason'] ?: 'Not stated') ?></p>
    <form method="post" action="<?= e(url('/booking/' . $booking['reference_code'] . '/reupload')) ?>" enctype="multipart/form-data" class="stack" novalidate data-once>
        <?= csrf_field() ?>
        <div class="field">
            <label for="gcash_reference_no">GCash reference number</label>
            <input id="gcash_reference_no" name="gcash_reference_no" type="tel" inputmode="numeric" value="<?= e(old('gcash_reference_no')) ?>" maxlength="30" required<?= invalid_attr('gcash_reference_no') ?>>
            <?= error_html('gcash_reference_no') ?>
        </div>
        <div class="field">
            <label for="receipt">New GCash screenshot</label>
            <input id="receipt" name="receipt" type="file" accept="image/*" required data-compress<?= invalid_attr('receipt') ?>>
            <div class="receipt-preview" id="receipt-preview" hidden>
                <img id="receipt-img" alt="Preview of your screenshot" width="120" height="120">
                <p class="small muted" id="receipt-info"></p>
            </div>
            <?= error_html('receipt') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send new screenshot</button>
    </form>
</section>
<?php endif; ?>

<section class="card section" aria-labelledby="det-title">
    <h2 class="card-title" id="det-title">Booking details</h2>
    <div class="card-row"><span>Event</span><strong><?= e($booking['event_type']) ?> · <?= e($booking['package_name']) ?></strong></div>
    <div class="card-row"><span>Date</span><strong><?= e(date_long($booking['event_date'])) ?></strong></div>
    <?php if ($booking['start_time']): ?>
    <div class="card-row"><span>Time</span><strong><?= e(time12($booking['start_time'])) ?><?= $booking['end_time'] ? ' – ' . e(time12($booking['end_time'])) : '' ?></strong></div>
    <?php endif; ?>
    <div class="card-row"><span>Venue</span><strong><?= e($booking['venue']) ?></strong></div>
    <div class="card-row"><span>Total</span><strong class="money"><?= e(peso($booking['total_amount'])) ?></strong></div>
    <div class="card-row"><span>Paid so far</span><strong class="money"><?= e(peso($paid)) ?></strong></div>
    <?php if (!$cancelled): ?>
    <div class="card-row"><span>Balance</span><strong class="money"><?= e(peso($balance)) ?></strong></div>
    <?php endif; ?>
    <div class="card-row"><span>Payment</span>
        <span><?php
            if ($cancelled) {
                echo partial('partials/tag', ['class' => 'cancelled', 'label' => 'Cancelled']);
            } elseif ($payment) {
                $pm = ['pending' => ['pending', 'Being checked'], 'verified' => ['confirmed', 'Verified'], 'rejected' => ['rejected', 'Rejected']][$payment['status']];
                echo partial('partials/tag', ['class' => $pm[0], 'label' => $pm[1]]);
            }
        ?></span>
    </div>
    <?php if ($project): ?>
    <div class="card-row"><span>Project stage</span><span><?= partial('partials/tag', ['class' => 'info', 'label' => $stageLabels[$project['stage']]]) ?></span></div>
    <div class="card-row"><span>Expected by</span><strong><?= e(date_short($project['due_date'])) ?></strong></div>
    <?php endif; ?>
    <?php if ($payment && $payment['status'] === 'pending' && $payment['receipt_path']): ?>
    <p class="small muted mt-1">Your screenshot:</p>
    <img class="receipt-thumb" src="<?= e(url('/files/receipt/' . (int) $payment['id'] . '/thumb')) ?>" alt="Your GCash screenshot" width="120" height="160" loading="lazy">
    <?php endif; ?>
</section>

<div class="stack section">
    <?php if ($confirmed): ?>
        <a class="btn btn-primary btn-block" href="<?= e(url('/booking/' . $booking['reference_code'] . '/confirmation')) ?>">View booking confirmation</a>
    <?php else: ?>
        <span class="btn btn-primary btn-block" aria-disabled="true" role="link">View booking confirmation</span>
        <p class="small muted center mb-0">Available once your booking is confirmed.</p>
    <?php endif; ?>
    <a class="btn btn-outline btn-block" href="<?= e(url('/booking/' . $booking['reference_code'] . '/chat')) ?>"><?= icon('chat') ?> Message the studio</a>
</div>
