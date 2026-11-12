<?php /* Screen C5 (lookup): "My Booking" tab. Reference no. + mobile no. proves ownership. */ ?>
<section class="card">
    <h2 class="h3">Find your booking</h2>
    <p class="muted">Enter the reference number you received (like DPS-2026-0001) and the mobile number you booked with.</p>
    <form method="post" action="<?= e(url('/booking')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="reference_no">Reference number</label>
            <input id="reference_no" name="reference_no" type="text" value="<?= e(old('reference_no')) ?>" placeholder="DPS-2026-0001"
                   autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="30" enterkeyhint="next" required<?= invalid_attr('reference_no') ?>>
            <?= error_html('reference_no') ?>
        </div>
        <div class="field">
            <label for="mobile">Mobile number</label>
            <input id="mobile" name="mobile" type="tel" inputmode="numeric" value="<?= e(old('mobile')) ?>" placeholder="0917 123 4567"
                   autocomplete="tel" enterkeyhint="go" required<?= invalid_attr('mobile') ?>>
            <?= error_html('mobile') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">View my booking</button>
    </form>
</section>
<p class="small center section">Lost your reference number? <a href="<?= e(url('/chat')) ?>">Message the studio</a>.</p>
