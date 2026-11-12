<?php
/* Screen A20: Settings, Blocked Dates & Backups (/admin/settings, Owner only).
   Vars: $tab $settings $blockedDates $termsVersions $quickReplies $templates $backups */
?>

<nav class="chips section" aria-label="Settings sections">
    <a class="chip<?= $tab === 'general' ? ' is-active' : '' ?>" href="<?= e(url('/admin/settings', ['tab' => 'general'])) ?>"<?= $tab === 'general' ? ' aria-current="page"' : '' ?>>
        Studio &amp; Rules
    </a>
    <a class="chip<?= $tab === 'blocked' ? ' is-active' : '' ?>" href="<?= e(url('/admin/settings', ['tab' => 'blocked'])) ?>"<?= $tab === 'blocked' ? ' aria-current="page"' : '' ?>>
        Blocked Dates (<?= count($blockedDates) ?>)
    </a>
    <a class="chip<?= $tab === 'terms' ? ' is-active' : '' ?>" href="<?= e(url('/admin/settings', ['tab' => 'terms'])) ?>"<?= $tab === 'terms' ? ' aria-current="page"' : '' ?>>
        Terms &amp; Policy
    </a>
    <a class="chip<?= $tab === 'templates' ? ' is-active' : '' ?>" href="<?= e(url('/admin/settings', ['tab' => 'templates'])) ?>"<?= $tab === 'templates' ? ' aria-current="page"' : '' ?>>
        Templates &amp; Replies
    </a>
    <a class="chip<?= $tab === 'backups' ? ' is-active' : '' ?>" href="<?= e(url('/admin/settings', ['tab' => 'backups'])) ?>"<?= $tab === 'backups' ? ' aria-current="page"' : '' ?>>
        Backups (<?= count($backups) ?>)
    </a>
</nav>

<?php if ($tab === 'general'): ?>
    <form method="post" action="<?= e(url('/admin/settings')) ?>" enctype="multipart/form-data" class="stack section" novalidate>
        <?= csrf_field() ?>

        <section class="card stack">
            <h2 class="card-title">Studio information</h2>
            <div class="field">
                <label for="studio_name">Studio name <span class="req">*</span></label>
                <input id="studio_name" name="studio_name" type="text" required maxlength="120" value="<?= e(old('studio_name', $settings['studio_name'])) ?>"<?= invalid_attr('studio_name') ?>>
                <?= error_html('studio_name') ?>
            </div>
            <div class="field">
                <label for="studio_address">Studio address <span class="req">*</span></label>
                <input id="studio_address" name="studio_address" type="text" required maxlength="255" value="<?= e(old('studio_address', $settings['studio_address'])) ?>"<?= invalid_attr('studio_address') ?>>
                <?= error_html('studio_address') ?>
            </div>
            <div class="field">
                <label for="studio_phone">Contact number <span class="req">*</span></label>
                <input id="studio_phone" name="studio_phone" type="tel" required maxlength="40" value="<?= e(old('studio_phone', $settings['studio_phone'])) ?>"<?= invalid_attr('studio_phone') ?>>
                <?= error_html('studio_phone') ?>
            </div>
            <div class="field">
                <label for="studio_messenger_url">Facebook Messenger link</label>
                <input id="studio_messenger_url" name="studio_messenger_url" type="url" maxlength="255" value="<?= e(old('studio_messenger_url', $settings['studio_messenger_url'])) ?>" placeholder="https://m.me/...">
                <?= error_html('studio_messenger_url') ?>
            </div>
        </section>

        <section class="card stack">
            <h2 class="card-title">GCash payment details (Screen C4)</h2>
            <div class="field">
                <label for="gcash_name">GCash account name <span class="req">*</span></label>
                <input id="gcash_name" name="gcash_name" type="text" required maxlength="120" value="<?= e(old('gcash_name', $settings['gcash_name'])) ?>"<?= invalid_attr('gcash_name') ?>>
                <?= error_html('gcash_name') ?>
            </div>
            <div class="field">
                <label for="gcash_number">GCash mobile number <span class="req">*</span></label>
                <input id="gcash_number" name="gcash_number" type="tel" required maxlength="40" value="<?= e(old('gcash_number', $settings['gcash_number'])) ?>"<?= invalid_attr('gcash_number') ?>>
                <?= error_html('gcash_number') ?>
            </div>
            <div class="field">
                <label for="gcash_qr">GCash QR code image <span class="small muted">(optional JPG/PNG)</span></label>
                <?php if (!empty($settings['gcash_qr_path'])): ?>
                    <div class="section">
                        <img src="<?= e(url('/files/gcash-qr')) ?>" alt="Current GCash QR code" width="140" height="140">
                    </div>
                <?php endif; ?>
                <input id="gcash_qr" name="gcash_qr" type="file" accept="image/*"<?= invalid_attr('gcash_qr') ?>>
                <?= error_html('gcash_qr') ?>
            </div>
        </section>

        <section class="card stack">
            <h2 class="card-title">Booking &amp; capacity rules (§3.3)</h2>
            <div class="field">
                <label for="down_payment_mode">Down payment calculation mode</label>
                <select id="down_payment_mode" name="down_payment_mode">
                    <option value="percent"<?= old('down_payment_mode', $settings['down_payment_mode']) === 'percent' ? ' selected' : '' ?>>Percentage of package total (rounded up to nearest ₱50)</option>
                    <option value="fixed"<?= old('down_payment_mode', $settings['down_payment_mode']) === 'fixed' ? ' selected' : '' ?>>Fixed amount (₱)</option>
                </select>
            </div>
            <div class="field">
                <label for="down_payment_value">Default down payment value (% or ₱) <span class="req">*</span></label>
                <input id="down_payment_value" name="down_payment_value" type="text" inputmode="decimal" required value="<?= e(old('down_payment_value', $settings['down_payment_value'])) ?>"<?= invalid_attr('down_payment_value') ?>>
                <?= error_html('down_payment_value') ?>
            </div>
            <div class="field">
                <label for="max_events_per_day">Maximum confirmed events per day <span class="req">*</span></label>
                <input id="max_events_per_day" name="max_events_per_day" type="number" min="1" max="10" required value="<?= e(old('max_events_per_day', $settings['max_events_per_day'])) ?>"<?= invalid_attr('max_events_per_day') ?>>
                <?= error_html('max_events_per_day') ?>
            </div>
            <div class="field">
                <label for="default_delivery_days">Default editing delivery period (days after event) <span class="req">*</span></label>
                <input id="default_delivery_days" name="default_delivery_days" type="number" min="1" max="365" required value="<?= e(old('default_delivery_days', $settings['default_delivery_days'])) ?>"<?= invalid_attr('default_delivery_days') ?>>
                <?= error_html('default_delivery_days') ?>
            </div>
            <div class="field">
                <label for="balance_overdue_days">Balance overdue grace period (days after event date) <span class="req">*</span></label>
                <input id="balance_overdue_days" name="balance_overdue_days" type="number" min="0" max="90" required value="<?= e(old('balance_overdue_days', $settings['balance_overdue_days'])) ?>"<?= invalid_attr('balance_overdue_days') ?>>
                <?= error_html('balance_overdue_days') ?>
            </div>
            <div class="field">
                <label for="event_types">Event types <span class="small muted">(one per line)</span> <span class="req">*</span></label>
                <textarea id="event_types" name="event_types" rows="6" required<?= invalid_attr('event_types') ?>><?= e(old('event_types', $settings['event_types'])) ?></textarea>
                <?= error_html('event_types') ?>
            </div>
        </section>

        <section class="card stack-s">
            <div class="row-between">
                <div>
                    <h2 class="card-title">Account security</h2>
                    <p class="small muted">Update your login password at any time.</p>
                </div>
                <a class="btn btn-outline btn-small" href="<?= e(url('/account/password')) ?>"><?= icon('key') ?> Change password</a>
            </div>
        </section>

        <div class="action-bar">
            <button type="submit" class="btn btn-primary btn-block btn-lg">Save studio settings</button>
        </div>
    </form>

<?php elseif ($tab === 'blocked'): ?>
    <section class="card stack section">
        <h2 class="card-title">Block a calendar date</h2>
        <p class="small muted">Blocked dates are crossed out on the client availability calendar (C2) and refuse new bookings or reschedules (BR-1, BR-3).</p>
        <form method="post" action="<?= e(url('/admin/settings/blocked-dates')) ?>" class="stack-s" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="block_date">Date to block <span class="req">*</span></label>
                <input id="block_date" name="date" type="date" required value="<?= e(old('date')) ?>"<?= invalid_attr('date') ?>>
                <?= error_html('date') ?>
            </div>
            <div class="field">
                <label for="block_reason">Reason <span class="req">*</span></label>
                <input id="block_reason" name="reason" type="text" required maxlength="255" value="<?= e(old('reason')) ?>" placeholder="e.g. Studio holiday / Maintenance"<?= invalid_attr('reason') ?>>
                <?= error_html('reason') ?>
            </div>
            <button type="submit" class="btn btn-primary"><?= icon('plus') ?> Block date</button>
        </form>
    </section>

    <section class="section" aria-labelledby="blocked-list-title">
        <h2 class="h3" id="blocked-list-title">Blocked dates (<?= count($blockedDates) ?>)</h2>
        <?php if (empty($blockedDates)): ?>
            <div class="empty">
                <?= icon('calendar', 'icon icon-xl') ?>
                <p><strong>No blocked dates.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($blockedDates as $bd): ?>
                    <article class="card row-between">
                        <div>
                            <strong><?= e(date_long($bd['date'])) ?></strong>
                            <p class="small muted"><?= e($bd['reason'] ?: 'Blocked by studio') ?></p>
                        </div>
                        <form method="post" action="<?= e(url('/admin/settings/blocked-dates/' . (int) $bd['id'] . '/delete')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-small btn-outline">Unblock</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'terms'): ?>
    <?php $currentTerms = $termsVersions[0] ?? ['version' => '1', 'effective_from' => \App\Core\Clock::today(), 'body' => '']; ?>
    <section class="card stack section">
        <h2 class="card-title">Booking terms &amp; cancellation policy</h2>
        <p class="small muted">Active version: <strong>v<?= e($settings['current_terms_version']) ?></strong>. Clients must accept this policy on Screen C4 before submitting a booking.</p>

        <form method="post" action="<?= e(url('/admin/settings/terms')) ?>" class="stack" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="terms_version">Version label <span class="req">*</span></label>
                <input id="terms_version" name="version" type="text" required maxlength="10" value="<?= e(old('version', (string) $currentTerms['version'])) ?>" placeholder="e.g. 1 or 2"<?= invalid_attr('version') ?>>
                <?= error_html('version') ?>
            </div>
            <div class="field">
                <label for="terms_effective">Effective from <span class="req">*</span></label>
                <input id="terms_effective" name="effective_from" type="date" required value="<?= e(old('effective_from', (string) $currentTerms['effective_from'])) ?>"<?= invalid_attr('effective_from') ?>>
                <?= error_html('effective_from') ?>
            </div>
            <div class="field">
                <label for="terms_body">Terms text <span class="req">*</span></label>
                <textarea id="terms_body" name="body" rows="6" required maxlength="5000"<?= invalid_attr('body') ?>><?= e(old('body', (string) $currentTerms['body'])) ?></textarea>
                <?= error_html('body') ?>
            </div>
            <button type="submit" class="btn btn-primary">Save terms version</button>
        </form>
    </section>

    <section class="section">
        <h2 class="h3">Version history (<?= count($termsVersions) ?>)</h2>
        <div class="stack">
            <?php foreach ($termsVersions as $tv): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <strong>Version <?= e($tv['version']) ?></strong>
                        <span class="small muted">Effective <?= e(date_short($tv['effective_from'])) ?></span>
                    </div>
                    <p class="small"><?= nl2br(e($tv['body'])) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($tab === 'templates'): ?>
    <section class="card stack section">
        <h2 class="card-title">Chat quick replies (Screen A19)</h2>
        <p class="small muted">One-tap reply chips shown at the bottom of the Studio Inbox thread view.</p>

        <form method="post" action="<?= e(url('/admin/settings/quick-replies')) ?>" class="stack-s section" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="qr_title">Chip label <span class="req">*</span></label>
                <input id="qr_title" name="title" type="text" required maxlength="60" value="<?= e(old('title')) ?>" placeholder="e.g. Directions">
            </div>
            <div class="field">
                <label for="qr_body">Reply message <span class="req">*</span></label>
                <textarea id="qr_body" name="body" rows="2" required maxlength="1000" placeholder="Full message inserted when tapped..."><?= e(old('body')) ?></textarea>
            </div>
            <input type="hidden" name="is_active" value="1">
            <button type="submit" class="btn btn-primary btn-small"><?= icon('plus') ?> Add quick reply</button>
        </form>

        <div class="stack-s section">
            <?php foreach ($quickReplies as $qr): ?>
                <div class="card-row">
                    <div>
                        <strong><?= e($qr['title']) ?></strong>
                        <p class="small muted"><?= e($qr['body']) ?></p>
                    </div>
                    <form method="post" action="<?= e(url('/admin/settings/quick-replies/' . (int) $qr['id'] . '/delete')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-small btn-outline">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="section">
        <h2 class="h3">Client message templates (Screen A12)</h2>
        <p class="small muted">Available placeholders: <code>[Client name]</code> <code>[Event]</code> <code>[Event date]</code> <code>[Balance]</code> <code>[Reference no.]</code> <code>[Studio]</code></p>
        <div class="stack section">
            <?php foreach ($templates as $tpl): ?>
                <?php $tid = (int) $tpl['id']; ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <strong><?= e($tpl['title']) ?> (<code><?= e($tpl['template_key']) ?></code>)</strong>
                        <span class="tag <?= (int) $tpl['is_active'] === 1 ? 'tag-confirmed' : 'tag-cancelled' ?>">
                            <?= (int) $tpl['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    <details>
                        <summary class="btn btn-small btn-outline">Edit template</summary>
                        <form method="post" action="<?= e(url('/admin/settings/templates/' . $tid)) ?>" class="stack-s section">
                            <?= csrf_field() ?>
                            <div class="field">
                                <label for="tpl_title_<?= $tid ?>">Title</label>
                                <input id="tpl_title_<?= $tid ?>" name="title" type="text" required maxlength="100" value="<?= e($tpl['title']) ?>">
                            </div>
                            <div class="field">
                                <label for="tpl_tl_<?= $tid ?>">Filipino (Tagalog) body</label>
                                <textarea id="tpl_tl_<?= $tid ?>" name="body_tl" rows="3" required maxlength="3000"><?= e($tpl['body_tl']) ?></textarea>
                            </div>
                            <div class="field">
                                <label for="tpl_en_<?= $tid ?>">English body</label>
                                <textarea id="tpl_en_<?= $tid ?>" name="body_en" rows="3" required maxlength="3000"><?= e($tpl['body_en']) ?></textarea>
                            </div>
                            <input type="hidden" name="is_active" value="1">
                            <button type="submit" class="btn btn-primary btn-small">Save template</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($tab === 'backups'): ?>
    <section class="card stack section">
        <h2 class="card-title">Database backup (§9.6)</h2>
        <p class="small muted">
            Generates a complete <code>.sql</code> dump of all tables into <code>storage/backups/</code>.
            For off-site safety, download the backup file and upload a copy to your Google Drive or cloud storage, then tick <strong>Mark cloud copy done</strong>.
        </p>
        <form method="post" action="<?= e(url('/admin/backups')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-block"><?= icon('folder') ?> Backup now (.sql dump)</button>
        </form>
        <p class="small muted">CLI scheduled backup command: <code>php database/backup.php</code></p>
    </section>

    <section class="section" aria-labelledby="backup-hist-title">
        <h2 class="h3" id="backup-hist-title">Backup history (<?= count($backups) ?>)</h2>
        <?php if (empty($backups)): ?>
            <div class="empty">
                <p><strong>No backups recorded yet.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($backups as $b): ?>
                    <?php $bid = (int) $b['id']; ?>
                    <article class="card stack-s">
                        <div class="row-between">
                            <strong><?= e($b['file_name']) ?></strong>
                            <span class="tag <?= !empty($b['cloud_copy_marked_at']) ? 'tag-confirmed' : 'tag-pending' ?>">
                                <?= !empty($b['cloud_copy_marked_at']) ? 'Cloud copy ✓' : 'Local only' ?>
                            </span>
                        </div>
                        <p class="small muted">
                            Created: <?= e(datetime_short($b['created_at'])) ?>
                            · Type: <?= e(ucfirst((string) $b['type'])) ?>
                            · Size: <?= e(number_format(max(1, (int) round(((int) $b['size_bytes']) / 1024)))) ?> KB
                            <?php if (!empty($b['created_by_name'])): ?>
                                · By <?= e($b['created_by_name']) ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($b['cloud_copy_marked_at'])): ?>
                            <p class="small muted">Cloud copy confirmed: <?= e(datetime_short($b['cloud_copy_marked_at'])) ?></p>
                        <?php endif; ?>
                        <div class="row-gap">
                            <a class="btn btn-small btn-outline" href="<?= e(url('/admin/backups/' . $bid . '/download')) ?>">Download .sql</a>
                            <?php if (empty($b['cloud_copy_marked_at'])): ?>
                                <form method="post" action="<?= e(url('/admin/backups/' . $bid . '/cloud-copy')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-small btn-primary">Mark cloud copy done</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
