<?php
/**
 * Screen A5: New Booking Entry (Admin).
 * Supports walk-in / call / messenger inquiries, live availability checking,
 * client profile matching by mobile, and "Down payment received now" direct confirmation.
 * Strict CSP compliant: no inline styles or scripts.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Clock;

$today = Clock::today();
$canRecordPayments = $canRecordPayments ?? (Auth::isOwner() || Auth::can('record_payments'));
?>
<p class="eyebrow">Screen A5 · New Booking Entry</p>

<form method="post" action="<?= e(url('/admin/bookings/new')) ?>" class="stack" data-once>
    <?= csrf_field() ?>

    <!-- Channel & Client Information -->
    <fieldset class="card">
        <legend class="h3">Client &amp; Channel</legend>

        <div class="field">
            <span class="label">Contact channel <span class="req">*</span></span>
            <div class="chips">
                <?php
                $channels = [
                    'messenger' => 'Facebook Messenger',
                    'call'      => 'Phone Call',
                    'walk_in'   => 'Walk-in',
                    'referral'  => 'Referral',
                    'website'   => 'Website',
                ];
                $selectedChannel = old('source_channel', 'messenger');
                foreach ($channels as $key => $lbl):
                ?>
                    <label class="chip">
                        <input type="radio" name="source_channel" value="<?= e($key) ?>"<?= $selectedChannel === $key ? ' checked' : '' ?> class="sr-only">
                        <span><?= e($lbl) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?= error_html('source_channel') ?>
        </div>

        <div class="field mt-1">
            <label for="new-full-name">Client full name <span class="req">*</span></label>
            <input type="text" id="new-full-name" name="full_name" value="<?= e(old('full_name')) ?>" required maxlength="120" placeholder="e.g. Maria Santos"<?= invalid_attr('full_name') ?>>
            <?= error_html('full_name') ?>
        </div>

        <div class="field mt-1">
            <label for="new-mobile">Mobile number <span class="req">*</span></label>
            <input type="tel" id="new-mobile" name="mobile" value="<?= e(old('mobile')) ?>" required inputmode="numeric" placeholder="0917 123 4567"<?= invalid_attr('mobile') ?>>
            <p class="hint">Existing client record will be matched automatically by mobile number.</p>
            <?= error_html('mobile') ?>
        </div>

        <div class="field mt-1">
            <label for="new-messenger">Messenger name or profile link (optional)</label>
            <input type="text" id="new-messenger" name="messenger_name" value="<?= e(old('messenger_name')) ?>" maxlength="150" placeholder="e.g. m.me/maria.santos"<?= invalid_attr('messenger_name') ?>>
            <?= error_html('messenger_name') ?>
        </div>
    </fieldset>

    <!-- Event Schedule & Location -->
    <fieldset class="card">
        <legend class="h3">Event Details</legend>

        <div class="field">
            <label for="new-event-type">Event type <span class="req">*</span></label>
            <select id="new-event-type" name="event_type" required<?= invalid_attr('event_type') ?>>
                <option value="">Select event type...</option>
                <?php foreach ($eventTypes as $type): ?>
                    <option value="<?= e($type) ?>"<?= old('event_type') === $type ? ' selected' : '' ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
            <?= error_html('event_type') ?>
        </div>

        <div class="field mt-1">
            <label for="new-event-date">Event date <span class="req">*</span></label>
            <input type="date" id="new-event-date" name="event_date" min="<?= e($today) ?>" value="<?= e(old('event_date', $date)) ?>" required data-availability-check data-target="avail-badge-a5"<?= invalid_attr('event_date') ?>>
            <div id="avail-badge-a5" class="mt-1" aria-live="polite"></div>
            <?= error_html('event_date') ?>
        </div>

        <div class="grid-2 mt-1">
            <div class="field">
                <label for="new-start-time">Start time</label>
                <input type="time" id="new-start-time" name="start_time" value="<?= e(old('start_time')) ?>"<?= invalid_attr('start_time') ?>>
                <?= error_html('start_time') ?>
            </div>
            <div class="field">
                <label for="new-end-time">End time</label>
                <input type="time" id="new-end-time" name="end_time" value="<?= e(old('end_time')) ?>"<?= invalid_attr('end_time') ?>>
                <?= error_html('end_time') ?>
            </div>
        </div>

        <div class="field mt-1">
            <label for="new-venue">Venue &amp; City <span class="req">*</span></label>
            <input type="text" id="new-venue" name="venue" value="<?= e(old('venue')) ?>" required maxlength="255" placeholder="e.g. Club Balai Isabel, Talisay, Batangas"<?= invalid_attr('venue') ?>>
            <?= error_html('venue') ?>
        </div>

        <div class="field mt-1">
            <label for="new-special-requests">Special requests / coverage instructions</label>
            <textarea id="new-special-requests" name="special_requests" rows="2" maxlength="1000" placeholder="e.g. Needs drone shot; separate prep location"><?= e(old('special_requests')) ?></textarea>
            <?= error_html('special_requests') ?>
        </div>
    </fieldset>

    <!-- Package & Add-ons -->
    <fieldset class="card">
        <legend class="h3">Package &amp; Add-ons</legend>

        <div class="field">
            <label for="new-package-id">Package <span class="req">*</span></label>
            <select id="new-package-id" name="package_id" required data-package-select<?= invalid_attr('package_id') ?>>
                <option value="">Select package...</option>
                <?php foreach ($packages as $pkg): ?>
                    <option value="<?= (int) $pkg['id'] ?>" data-price="<?= (float) $pkg['price'] ?>" data-override="<?= e((string) ($pkg['down_payment_override'] ?? '')) ?>"<?= (int) old('package_id') === (int) $pkg['id'] ? ' selected' : '' ?>>
                        <?= e($pkg['name']) ?> (<?= e(peso($pkg['price'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?= error_html('package_id') ?>
        </div>

        <?php if (!empty($addons)): ?>
            <div class="field mt-1">
                <span class="label">Add-on services</span>
                <?php
                $oldAddons = (array) old('addons', []);
                foreach ($addons as $addon):
                    $checked = in_array((int) $addon['id'], array_map('intval', $oldAddons), true);
                ?>
                    <label class="check">
                        <input type="checkbox" name="addons[]" value="<?= (int) $addon['id'] ?>" data-addon-price="<?= (float) $addon['price'] ?>"<?= $checked ? ' checked' : '' ?>>
                        <span><?= e($addon['name']) ?> (+<?= e(peso($addon['price'])) ?>)</span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </fieldset>

    <!-- Down Payment Received Now -->
    <fieldset class="card">
        <legend class="h3">Payment Entry</legend>

        <?php if ($canRecordPayments): ?>
        <label class="check">
            <input type="checkbox" id="toggle-down-payment" name="down_payment_received" value="1" data-toggle-down-payment<?= old('down_payment_received') ? ' checked' : '' ?>>
            <span><strong>Down payment received now</strong> (Confirms booking on the spot &amp; issues official receipt)</span>
        </label>
        <p class="hint">Leave unchecked if this is an unpaid inquiry. The booking will stay Pending until payment is recorded.</p>
        <?= error_html('down_payment_received') ?>

        <div id="down-payment-details" class="mt-1"<?= old('down_payment_received') ? '' : ' hidden' ?>>
            <div class="field">
                <label for="new-payment-amount">Amount received (₱) <span class="req">*</span></label>
                <input type="number" id="new-payment-amount" name="payment_amount" step="0.01" min="1" value="<?= e(old('payment_amount')) ?>" placeholder="e.g. 2500.00"<?= invalid_attr('payment_amount') ?>>
                <?= error_html('payment_amount') ?>
            </div>

            <div class="field mt-1">
                <label for="new-payment-method">Payment method <span class="req">*</span></label>
                <select id="new-payment-method" name="payment_method">
                    <option value="cash"<?= old('payment_method', 'cash') === 'cash' ? ' selected' : '' ?>>Cash</option>
                    <option value="gcash"<?= old('payment_method') === 'gcash' ? ' selected' : '' ?>>GCash</option>
                    <option value="other"<?= old('payment_method') === 'other' ? ' selected' : '' ?>>Other</option>
                </select>
            </div>

            <div class="field mt-1" id="field-gcash-ref">
                <label for="new-payment-ref">GCash reference number</label>
                <input type="text" id="new-payment-ref" name="payment_ref" value="<?= e(old('payment_ref')) ?>" inputmode="numeric" placeholder="e.g. 1001234567890"<?= invalid_attr('payment_ref') ?>>
                <?= error_html('payment_ref') ?>
            </div>

            <div class="field mt-1">
                <label for="new-payment-notes">Payment notes (optional)</label>
                <input type="text" id="new-payment-notes" name="payment_notes" value="<?= e(old('payment_notes')) ?>" maxlength="200" placeholder="e.g. Paid in cash at reception">
            </div>
        </div>
        <?php else: ?>
            <p class="hint mb-0">
                New bookings will be recorded as <strong>Pending</strong>. You do not have permission to record payments on the spot; an authorized user must record or verify payment from the booking hub.
            </p>
        <?php endif; ?>
    </fieldset>

    <div class="action-bar">
        <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('check') ?> Create booking</button>
        <a class="btn btn-block mt-1" href="<?= e(url('/admin/bookings')) ?>">Cancel</a>
    </div>
</form>
