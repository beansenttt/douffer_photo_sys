<?php
/* Screen C3: Booking Details (step 1 of 2). Vars: $draft $date $packages $eventTypes
   Values: previous input after an error > saved draft > defaults. */
$val = static fn (string $k, $fallback = '') => old($k, $draft[$k] ?? $fallback);
$mobileVal = old('mobile', isset($draft['mobile']) ? format_mobile($draft['mobile']) : '');
$selPackage = (string) old('package_id', (string) ($draft['package_id'] ?? ''));
$selType = (string) $val('event_type');
?>
<ol class="steps steps-2" aria-label="Progress">
    <li aria-current="step"><span>1</span> Your details</li>
    <li><span>2</span> Payment</li>
</ol>

<form method="post" action="<?= e(url('/book')) ?>" class="stack" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="full_name">Full name <span class="req">*</span></label>
        <input id="full_name" name="full_name" type="text" value="<?= e($val('full_name')) ?>" autocomplete="name" enterkeyhint="next" maxlength="120" required<?= invalid_attr('full_name') ?>>
        <?= error_html('full_name') ?>
    </div>
    <div class="field">
        <label for="mobile">Mobile number <span class="req">*</span></label>
        <input id="mobile" name="mobile" type="tel" inputmode="numeric" value="<?= e($mobileVal) ?>" autocomplete="tel" placeholder="0917 123 4567" enterkeyhint="next" required<?= invalid_attr('mobile') ?>>
        <?= error_html('mobile') ?>
    </div>
    <div class="field">
        <label for="messenger_name">Messenger name / link</label>
        <input id="messenger_name" name="messenger_name" type="text" value="<?= e($val('messenger_name')) ?>" maxlength="150" enterkeyhint="next"<?= invalid_attr('messenger_name') ?>>
        <?= error_html('messenger_name') ?>
    </div>
    <div class="field">
        <label for="event_type">Event type <span class="req">*</span></label>
        <select id="event_type" name="event_type" required<?= invalid_attr('event_type') ?>>
            <option value="">Choose…</option>
            <?php foreach ($eventTypes as $t): ?>
                <option value="<?= e($t) ?>"<?= $selType === $t ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <?= error_html('event_type') ?>
    </div>
    <div class="field">
        <label for="package_id">Package <span class="req">*</span></label>
        <select id="package_id" name="package_id" required<?= invalid_attr('package_id') ?>>
            <option value="">Choose…</option>
            <?php foreach ($packages as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $selPackage === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?> – <?= e(peso($p['price'])) ?></option>
            <?php endforeach; ?>
        </select>
        <?= error_html('package_id') ?>
    </div>
    <div class="field">
        <label for="event_date">Event date <span class="req">*</span></label>
        <input id="event_date" name="event_date" type="date" value="<?= e(old('event_date', $date)) ?>" min="<?= e(\App\Core\Clock::today()) ?>" required<?= invalid_attr('event_date') ?>>
        <?= error_html('event_date') ?>
        <p class="hint"><a href="<?= e(url('/availability', ['date' => $date])) ?>">Check another date</a></p>
    </div>
    <div class="grid-2">
        <div class="field">
            <label for="start_time">Start time</label>
            <input id="start_time" name="start_time" type="time" value="<?= e($val('start_time')) ?>"<?= invalid_attr('start_time') ?>>
            <?= error_html('start_time') ?>
        </div>
        <div class="field">
            <label for="end_time">End time</label>
            <input id="end_time" name="end_time" type="time" value="<?= e($val('end_time')) ?>"<?= invalid_attr('end_time') ?>>
            <?= error_html('end_time') ?>
        </div>
    </div>
    <div class="field">
        <label for="venue">Venue / location <span class="req">*</span></label>
        <input id="venue" name="venue" type="text" value="<?= e($val('venue')) ?>" maxlength="255" autocomplete="street-address" enterkeyhint="next" required<?= invalid_attr('venue') ?>>
        <?= error_html('venue') ?>
    </div>
    <div class="field">
        <label for="special_requests">Special requests (optional)</label>
        <textarea id="special_requests" name="special_requests" maxlength="1000" rows="3"<?= invalid_attr('special_requests') ?>><?= e($val('special_requests')) ?></textarea>
        <?= error_html('special_requests') ?>
    </div>
    <div class="field">
        <label class="check">
            <input type="checkbox" name="privacy_consent" value="1"<?= old('privacy_consent', isset($draft['privacy_consent_at']) ? '1' : '') !== '' ? ' checked' : '' ?><?= invalid_attr('privacy_consent') ?>>
            <span>I agree that the studio may keep my details to handle my booking, as explained in the <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">privacy notice</a> (Data Privacy Act of 2012). <span class="req">*</span></span>
        </label>
        <?= error_html('privacy_consent') ?>
    </div>

    <div class="action-bar">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Review and pay down payment</button>
    </div>
</form>
