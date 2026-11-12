<?php
/* Screen C4: Summary & GCash Payment (step 2 of 2).
   Vars: $draft $package $total $down $balance $terms $gcash */
?>
<ol class="steps steps-2" aria-label="Progress">
    <li class="done"><span><?= icon('check', 'icon icon-s') ?></span> Your details</li>
    <li aria-current="step"><span>2</span> Payment</li>
</ol>

<section class="card" aria-labelledby="sum-title">
    <h2 class="card-title" id="sum-title"><?= e($package['name']) ?></h2>
    <p class="card-meta"><?= e(date_long($draft['event_date'])) ?><?= $draft['start_time'] !== '' ? ' · ' . e(time12($draft['start_time'])) : '' ?></p>
    <p class="card-meta">Venue: <?= e($draft['venue']) ?></p>
    <div class="card-row"><span>Total</span><strong class="money"><?= e(peso($total)) ?></strong></div>
    <div class="card-row"><span>Down payment <span class="small muted">(reserves your date)</span></span><strong class="money"><?= e(peso($down)) ?></strong></div>
    <div class="card-row"><span>Balance (pay on or before the event)</span><strong class="money"><?= e(peso($balance)) ?></strong></div>
</section>

<form method="post" action="<?= e(url('/book/pay')) ?>" enctype="multipart/form-data" class="stack section" novalidate data-once>
    <?= csrf_field() ?>
    <section class="card gcash-box" aria-labelledby="gcash-title">
        <h2 class="card-title" id="gcash-title">Pay the down payment by GCash</h2>
        <?php if (!empty($gcash['qr'])): ?>
            <div class="qr"><img src="<?= e(url('/files/gcash-qr')) ?>" alt="GCash QR code" width="160" height="160"></div>
        <?php else: ?>
            <div class="qr" aria-label="GCash QR code placeholder">QR CODE</div>
        <?php endif; ?>
        <div class="card-row"><span>GCash name</span><strong><?= e($gcash['name']) ?></strong></div>
        <div class="card-row"><span>GCash no.</span><strong><?= e($gcash['number']) ?></strong></div>
        <div class="card-row"><span>Amount</span><strong class="money"><?= e(peso($down)) ?></strong></div>
    </section>

    <div class="field">
        <label for="gcash_reference_no">GCash reference number <span class="req">*</span></label>
        <input id="gcash_reference_no" name="gcash_reference_no" type="tel" inputmode="numeric" autocomplete="off" maxlength="30"
               value="<?= e(old('gcash_reference_no')) ?>" placeholder="13-digit number from your GCash receipt" enterkeyhint="done" required<?= invalid_attr('gcash_reference_no') ?>>
        <?= error_html('gcash_reference_no') ?>
    </div>

    <div class="field">
        <label for="receipt">GCash screenshot <span class="req">*</span></label>
        <input id="receipt" name="receipt" type="file" accept="image/*" required data-compress<?= invalid_attr('receipt') ?>>
        <p class="hint">Take a photo or choose one from your gallery. It is made smaller automatically to save your data.</p>
        <div class="receipt-preview" id="receipt-preview" hidden>
            <img id="receipt-img" alt="Preview of your screenshot" width="120" height="120">
            <p class="small muted" id="receipt-info"></p>
        </div>
        <?= error_html('receipt') ?>
    </div>

    <details class="card terms">
        <summary>Read the terms (version <?= e(\App\Core\Settings::get('current_terms_version')) ?>)</summary>
        <p class="small"><?= e($terms) ?></p>
    </details>

    <div class="field">
        <label class="check">
            <input type="checkbox" name="terms_ack" value="1"<?= old('terms_ack') !== '' ? ' checked' : '' ?><?= invalid_attr('terms_ack') ?>>
            <span>I understand the down payment is non-refundable if I cancel. Rescheduling is free if the new date is open. <span class="req">*</span></span>
        </label>
        <?= error_html('terms_ack') ?>
    </div>

    <div class="action-bar">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Submit booking</button>
    </div>
</form>
