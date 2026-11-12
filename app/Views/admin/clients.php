<?php
/* Screen A14: Clients & Inquiries (/admin/clients).
   Vars: $clients $q $filter $counts */
$channelLabels = [
    'messenger' => 'Messenger',
    'call'      => 'Phone call',
    'walk_in'   => 'Walk-in',
    'referral'  => 'Referral',
    'website'   => 'Website',
];
?>

<form method="get" action="<?= e(url('/admin/clients')) ?>" class="search-bar section">
    <?php if ($filter !== 'all'): ?>
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <?php endif; ?>
    <div class="field-inline">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, mobile, or Messenger..." aria-label="Search clients">
        <button type="submit" class="btn btn-primary"><?= icon('search') ?> Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-outline" href="<?= e(url('/admin/clients', $filter !== 'all' ? ['filter' => $filter] : [])) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<nav class="chips section" aria-label="Filter clients by channel">
    <?php
    $chipItems = [
        'all'       => 'All (' . ($counts['all'] ?? 0) . ')',
        'messenger' => 'Messenger (' . ($counts['messenger'] ?? 0) . ')',
        'call'      => 'Call (' . ($counts['call'] ?? 0) . ')',
        'walk_in'   => 'Walk-in (' . ($counts['walk_in'] ?? 0) . ')',
        'referral'  => 'Referral (' . ($counts['referral'] ?? 0) . ')',
        'website'   => 'Website (' . ($counts['website'] ?? 0) . ')',
        'archived'  => 'Archived (' . ($counts['archived'] ?? 0) . ')',
    ];
    foreach ($chipItems as $key => $label):
        $active = ($filter === $key);
        $params = ['filter' => $key];
        if ($q !== '') {
            $params['q'] = $q;
        }
    ?>
        <a class="chip<?= $active ? ' is-active' : '' ?>" href="<?= e(url('/admin/clients', $params)) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<details class="card section">
    <summary class="btn btn-outline btn-block"><?= icon('plus') ?> Add client / Record inquiry</summary>
    <form method="post" action="<?= e(url('/admin/clients')) ?>" class="stack section" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="new_full_name">Client full name <span class="req">*</span></label>
            <input id="new_full_name" name="full_name" type="text" required maxlength="120" value="<?= e(old('full_name')) ?>" placeholder="e.g. Elena Villanueva"<?= invalid_attr('full_name') ?>>
            <?= error_html('full_name') ?>
        </div>
        <div class="field">
            <label for="new_mobile">Mobile number <span class="req">*</span></label>
            <input id="new_mobile" name="mobile" type="tel" inputmode="numeric" required maxlength="20" value="<?= e(old('mobile')) ?>" placeholder="0917 123 4567"<?= invalid_attr('mobile') ?>>
            <?= error_html('mobile') ?>
        </div>
        <div class="field">
            <label for="new_messenger">Messenger name / link</label>
            <input id="new_messenger" name="messenger_name" type="text" maxlength="150" value="<?= e(old('messenger_name')) ?>" placeholder="Optional">
        </div>
        <div class="field">
            <label for="new_channel">Contact channel <span class="req">*</span></label>
            <select id="new_channel" name="contact_channel" required>
                <?php foreach ($channelLabels as $val => $lbl): ?>
                    <option value="<?= e($val) ?>"<?= old('contact_channel', 'messenger') === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="new_note">Initial inquiry note (optional)</label>
            <textarea id="new_note" name="note" rows="3" maxlength="2000" placeholder="What did the client ask about? Date, event type, package..."><?= e(old('note')) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Save client &amp; inquiry</button>
    </form>
</details>

<?php if (empty($clients)): ?>
    <div class="empty section">
        <?= icon('users', 'icon icon-xl') ?>
        <p><strong>No clients found.</strong></p>
        <p class="muted">Try another search or add a new client inquiry above.</p>
    </div>
<?php else: ?>
    <div class="stack section">
        <?php foreach ($clients as $c): ?>
            <?php $cid = (int) $c['id']; ?>
            <article class="card stack-s" id="client-<?= $cid ?>">
                <div class="row-between">
                    <div>
                        <h2 class="card-title">
                            <a href="<?= e(url('/admin/clients/' . $cid)) ?>"><?= e($c['full_name']) ?></a>
                        </h2>
                        <p class="card-meta">
                            <a href="tel:<?= e($c['mobile_normalized']) ?>"><?= e(format_mobile($c['mobile'])) ?></a>
                            · <span class="tag tag-neutral"><?= e($channelLabels[$c['contact_channel']] ?? ucfirst((string) $c['contact_channel'])) ?></span>
                            <?php if (!empty($c['messenger_name'])): ?>
                                · FB: <?= e($c['messenger_name']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="row-gap">
                        <?php if ((int) $c['is_archived'] === 1): ?>
                            <?= partial('partials/tag', ['status' => 'archived']) ?>
                        <?php endif; ?>
                        <a class="btn btn-small btn-outline" href="<?= e(url('/admin/clients/' . $cid)) ?>">Details</a>
                    </div>
                </div>

                <?php if (!empty($c['bookings'])): ?>
                    <div class="section">
                        <p class="eyebrow">Bookings (<?= count($c['bookings']) ?>)</p>
                        <div class="stack-s">
                            <?php foreach ($c['bookings'] as $b): ?>
                                <div class="card-row">
                                    <div>
                                        <a href="<?= e(url('/admin/bookings/' . (int) $b['id'])) ?>">
                                            <strong><?= e($b['reference_code']) ?></strong>
                                        </a>
                                        · <?= e($b['event_type']) ?> (<?= e($b['package_name']) ?>)
                                        <span class="small muted">· <?= e(date_short($b['event_date'])) ?></span>
                                    </div>
                                    <?= partial('partials/tag', ['status' => $b['status']]) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="section">
                    <p class="eyebrow">Inquiry history (<?= count($c['inquiries']) ?>)</p>
                    <?php if (empty($c['inquiries'])): ?>
                        <p class="small muted">No inquiry notes logged yet.</p>
                    <?php else: ?>
                        <ul class="bullet-list">
                            <?php foreach ($c['inquiries'] as $inq): ?>
                                <li>
                                    <strong>[<?= e($channelLabels[$inq['channel']] ?? ucfirst((string) $inq['channel'])) ?>]</strong>
                                    <?= e($inq['note']) ?>
                                    <span class="small muted">
                                        — <?= e($inq['author_name'] ?: 'Studio') ?>, <?= e(datetime_short($inq['created_at'])) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <details class="section">
                    <summary class="btn btn-small btn-outline"><?= icon('plus') ?> Add inquiry note</summary>
                    <form method="post" action="<?= e(url('/admin/clients/' . $cid . '/inquiries')) ?>" class="stack-s section">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="index">
                        <div class="field">
                            <label for="ch-<?= $cid ?>">Channel</label>
                            <select id="ch-<?= $cid ?>" name="channel" required>
                                <?php foreach ($channelLabels as $val => $lbl): ?>
                                    <option value="<?= e($val) ?>"<?= $c['contact_channel'] === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="note-<?= $cid ?>">Inquiry note <span class="req">*</span></label>
                            <textarea id="note-<?= $cid ?>" name="note" rows="2" required maxlength="2000" placeholder="Add call/Messenger/walk-in inquiry details..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-small">Save note</button>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
