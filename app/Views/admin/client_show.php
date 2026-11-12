<?php
/* Screen A14: Client Detail & Inquiry History (/admin/clients/{id}).
   Vars: $client $bookings $inquiries $conversations */
$cid = (int) $client['id'];
$channelLabels = [
    'messenger' => 'Messenger',
    'call'      => 'Phone call',
    'walk_in'   => 'Walk-in',
    'referral'  => 'Referral',
    'website'   => 'Website',
];
?>

<section class="card stack section">
    <div class="row-between">
        <div>
            <span class="eyebrow">Client profile</span>
            <h2 class="card-title"><?= e($client['full_name']) ?></h2>
        </div>
        <?php if ((int) $client['is_archived'] === 1): ?>
            <?= partial('partials/tag', ['status' => 'archived']) ?>
        <?php endif; ?>
    </div>

    <div class="stack-s">
        <div class="card-row">
            <span>Mobile</span>
            <strong><a href="tel:<?= e($client['mobile_normalized']) ?>"><?= e(format_mobile($client['mobile'])) ?></a></strong>
        </div>
        <div class="card-row">
            <span>Messenger</span>
            <strong><?= e($client['messenger_name'] ?: '—') ?></strong>
        </div>
        <div class="card-row">
            <span>Primary channel</span>
            <strong><?= e($channelLabels[$client['contact_channel']] ?? ucfirst((string) $client['contact_channel'])) ?></strong>
        </div>
        <div class="card-row">
            <span>Privacy consent</span>
            <span><?= !empty($client['privacy_consent_at']) ? e(datetime_short($client['privacy_consent_at'])) : 'Recorded by studio' ?></span>
        </div>
    </div>

    <div class="row-gap section">
        <a class="btn btn-primary btn-small" href="<?= e(url('/admin/bookings/new')) ?>"><?= icon('plus') ?> New booking</a>
        <?php if (\App\Core\Auth::can('archive_records')): ?>
            <form method="post" action="<?= e(url('/admin/clients/' . $cid . '/archive')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline btn-small">
                    <?= (int) $client['is_archived'] === 1 ? 'Restore client' : 'Archive client' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <details class="section">
        <summary class="btn btn-small btn-outline">Edit client details</summary>
        <form method="post" action="<?= e(url('/admin/clients/' . $cid)) ?>" class="stack section" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="edit_full_name">Full name <span class="req">*</span></label>
                <input id="edit_full_name" name="full_name" type="text" required maxlength="120" value="<?= e(old('full_name', $client['full_name'])) ?>">
            </div>
            <div class="field">
                <label for="edit_mobile">Mobile number <span class="req">*</span></label>
                <input id="edit_mobile" name="mobile" type="tel" inputmode="numeric" required maxlength="20" value="<?= e(old('mobile', $client['mobile'])) ?>">
            </div>
            <div class="field">
                <label for="edit_messenger">Messenger name</label>
                <input id="edit_messenger" name="messenger_name" type="text" maxlength="150" value="<?= e(old('messenger_name', (string) $client['messenger_name'])) ?>">
            </div>
            <div class="field">
                <label for="edit_channel">Contact channel</label>
                <select id="edit_channel" name="contact_channel">
                    <?php foreach ($channelLabels as $val => $lbl): ?>
                        <option value="<?= e($val) ?>"<?= $client['contact_channel'] === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </details>
</section>

<section class="card stack section" aria-labelledby="inq-heading">
    <h2 class="card-title" id="inq-heading">Inquiry history (<?= count($inquiries) ?>)</h2>

    <form method="post" action="<?= e(url('/admin/clients/' . $cid . '/inquiries')) ?>" class="stack-s">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="show">
        <div class="field">
            <label for="inq_channel">Channel</label>
            <select id="inq_channel" name="channel" required>
                <?php foreach ($channelLabels as $val => $lbl): ?>
                    <option value="<?= e($val) ?>"<?= $client['contact_channel'] === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="inq_note">Add inquiry note <span class="req">*</span></label>
            <textarea id="inq_note" name="note" rows="3" required maxlength="2000" placeholder="Log inquiry details, quote given, or follow-up notes..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-small"><?= icon('plus') ?> Add inquiry note</button>
    </form>

    <?php if (empty($inquiries)): ?>
        <p class="small muted section">No inquiry notes recorded yet.</p>
    <?php else: ?>
        <div class="stack-s section">
            <?php foreach ($inquiries as $inq): ?>
                <div class="card-row">
                    <div>
                        <p><strong>[<?= e($channelLabels[$inq['channel']] ?? ucfirst((string) $inq['channel'])) ?>]</strong> <?= nl2br(e($inq['note'])) ?></p>
                        <p class="small muted"><?= e($inq['author_name'] ?: 'Studio') ?> · <?= e(datetime_short($inq['created_at'])) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card stack section" aria-labelledby="bk-heading">
    <h2 class="card-title" id="bk-heading">Bookings (<?= count($bookings) ?>)</h2>
    <?php if (empty($bookings)): ?>
        <p class="small muted">No bookings for this client yet.</p>
    <?php else: ?>
        <div class="stack-s">
            <?php foreach ($bookings as $b): ?>
                <div class="card-row">
                    <div>
                        <a href="<?= e(url('/admin/bookings/' . (int) $b['id'])) ?>">
                            <strong><?= e($b['reference_code']) ?></strong>
                        </a>
                        · <?= e($b['event_type']) ?> – <?= e($b['package_name']) ?>
                        <p class="small muted"><?= e(date_long($b['event_date'])) ?> · Total <?= e(peso($b['total_amount'])) ?></p>
                    </div>
                    <?= partial('partials/tag', ['status' => $b['status']]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($conversations) && \App\Core\Auth::can('manage_messages')): ?>
    <section class="card stack section">
        <h2 class="card-title">Chat threads (<?= count($conversations) ?>)</h2>
        <div class="stack-s">
            <?php foreach ($conversations as $conv): ?>
                <div class="card-row">
                    <div>
                        <a href="<?= e(url('/admin/messages/' . (int) $conv['id'])) ?>">
                            <strong>Conversation #<?= (int) $conv['id'] ?></strong>
                            <?= !empty($conv['reference_code']) ? ' (' . e($conv['reference_code']) . ')' : ' (Pre-booking inquiry)' ?>
                        </a>
                        <p class="small muted">Last message: <?= e(datetime_short($conv['last_message_at'])) ?></p>
                    </div>
                    <span class="tag tag-neutral"><?= e(ucfirst((string) $conv['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
