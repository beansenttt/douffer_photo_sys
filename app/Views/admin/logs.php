<?php
/* Screen A21: Audit & System Logs Viewer (/admin/logs, Owner only).
   Vars: $tabs $tab $filters $streamCounts $users $distinctActions $distinctEntities
         $auditRows $bookingRows $projectHistRows $projectFileRows $messageLogRows
         $systemMsgRows $backupRows $rateLimitRows $errorLogLines */
$exportParams = array_filter(array_merge(['tab' => $tab], $filters), static fn($v): bool => $v !== '');
?>

<div class="no-print">
    <div class="row-between section">
        <p class="small muted"><?= icon('shield') ?> Append-only audit &amp; system logs (RA 10173 &amp; BR-8). Entries cannot be edited or deleted.</p>
        <div class="row-gap">
            <button type="button" class="btn btn-outline btn-small" data-print><?= icon('print') ?> Print</button>
            <a class="btn btn-primary btn-small" href="<?= e(url('/admin/logs/export.csv', $exportParams)) ?>">Export CSV</a>
        </div>
    </div>

    <nav class="chips section" aria-label="Log streams">
        <?php foreach ($tabs as $key => $label): ?>
            <?php $active = ($tab === $key); ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>"
               href="<?= e(url('/admin/logs', ['tab' => $key])) ?>"
               <?= $active ? ' aria-current="page"' : '' ?>>
                <?= e($label) ?> (<?= (int) ($streamCounts[$key] ?? 0) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (in_array($tab, ['audit', 'bookings', 'projects', 'notifications'], true)): ?>
        <form method="get" action="<?= e(url('/admin/logs')) ?>" class="card stack-s section">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">

            <div class="field">
                <label for="log_q">Search keyword / IP / reference no.</label>
                <input id="log_q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Search action, details, IP, reference code, or user...">
            </div>

            <div class="grid-2">
                <?php if ($tab === 'audit'): ?>
                    <div class="field">
                        <label for="log_action">Action</label>
                        <select id="log_action" name="action">
                            <option value="">All actions</option>
                            <?php foreach ($distinctActions as $act): ?>
                                <option value="<?= e($act) ?>"<?= $filters['action'] === $act ? ' selected' : '' ?>><?= e($act) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="log_entity">Entity</label>
                        <select id="log_entity" name="entity">
                            <option value="">All entities</option>
                            <?php foreach ($distinctEntities as $ent): ?>
                                <option value="<?= e($ent) ?>"<?= $filters['entity'] === $ent ? ' selected' : '' ?>><?= e($ent) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($tab === 'bookings'): ?>
                    <div class="field">
                        <label for="log_change_type">Change type</label>
                        <select id="log_change_type" name="change_type">
                            <option value="">All types</option>
                            <?php foreach (['created', 'status', 'reschedule', 'cancel', 'archive', 'restore', 'note'] as $ct): ?>
                                <option value="<?= e($ct) ?>"<?= $filters['change_type'] === $ct ? ' selected' : '' ?>><?= e(ucfirst($ct)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array($tab, ['audit', 'bookings'], true)): ?>
                    <div class="field">
                        <label for="log_actor">Actor / User</label>
                        <select id="log_actor" name="actor">
                            <option value="">Anyone</option>
                            <option value="public"<?= $filters['actor'] === 'public' ? ' selected' : '' ?>>Public / Client (unauthenticated)</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"<?= $filters['actor'] === (string) $u['id'] ? ' selected' : '' ?>>
                                    <?= e($u['name']) ?> (@<?= e($u['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="log_from">From date</label>
                    <input id="log_from" name="from" type="date" value="<?= e($filters['from']) ?>">
                </div>
                <div class="field">
                    <label for="log_to">To date</label>
                    <input id="log_to" name="to" type="date" value="<?= e($filters['to']) ?>">
                </div>
            </div>

            <div class="row-gap">
                <button type="submit" class="btn btn-primary btn-small"><?= icon('search') ?> Filter logs</button>
                <a class="btn btn-outline btn-small" href="<?= e(url('/admin/logs', ['tab' => $tab])) ?>">Reset</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($tab === 'audit'): ?>
    <section class="section" aria-labelledby="audit-log-heading">
        <h2 class="h3" id="audit-log-heading">1. Security &amp; Audit Logs (<?= count($auditRows) ?>)</h2>
        <?php if (empty($auditRows)): ?>
            <div class="empty">
                <p><strong>No audit log entries match your filter.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($auditRows as $row): ?>
                    <article class="card stack-s">
                        <div class="row-between">
                            <div>
                                <strong><code><?= e($row['action']) ?></code></strong>
                                <?php if (!empty($row['entity'])): ?>
                                    · <span class="tag tag-neutral">
                                        <?= e($row['entity']) ?><?= $row['entity_id'] !== null ? ' #' . (int) $row['entity_id'] : '' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="small muted">#<?= (int) $row['id'] ?> · <?= e(datetime_short($row['created_at'])) ?></span>
                        </div>
                        <p class="small">
                            <strong>Actor:</strong>
                            <?php if (!empty($row['actor_name'])): ?>
                                <?= e($row['actor_name']) ?> (@<?= e($row['actor_username']) ?>, <?= e($row['actor_role']) ?>)
                            <?php else: ?>
                                Public / Client
                            <?php endif; ?>
                            <?php if (!empty($row['ip'])): ?>
                                · <strong>IP:</strong> <code><?= e($row['ip']) ?></code>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($row['details'])): ?>
                            <pre class="small muted"><?= e($row['details']) ?></pre>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'bookings'): ?>
    <section class="section" aria-labelledby="bk-log-heading">
        <h2 class="h3" id="bk-log-heading">2. Booking Change &amp; Policy History Logs (<?= count($bookingRows) ?>)</h2>
        <?php if (empty($bookingRows)): ?>
            <div class="empty">
                <p><strong>No booking history entries match your filter.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($bookingRows as $row): ?>
                    <article class="card stack-s">
                        <div class="row-between">
                            <div>
                                <a href="<?= e(url('/admin/bookings/' . (int) $row['booking_id'])) ?>">
                                    <strong><?= e($row['reference_code']) ?></strong>
                                </a>
                                · <?= e($row['client_name']) ?>
                            </div>
                            <span class="tag tag-neutral"><?= e(ucfirst((string) $row['change_type'])) ?></span>
                        </div>
                        <p class="small">
                            <?php if ($row['old_value'] !== null && $row['old_value'] !== ''): ?>
                                <code><?= e($row['old_value']) ?></code> →
                            <?php endif; ?>
                            <strong><code><?= e($row['new_value'] ?? '—') ?></code></strong>
                        </p>
                        <?php if (!empty($row['reason'])): ?>
                            <p class="small muted">Reason: <?= e($row['reason']) ?></p>
                        <?php endif; ?>
                        <p class="small muted">
                            By <?= e($row['actor_name'] ?: 'Client / System') ?> · <?= e(datetime_short($row['changed_at'])) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'projects'): ?>
    <section class="section" aria-labelledby="proj-hist-heading">
        <h2 class="h3" id="proj-hist-heading">3a. Project Stage Transition History (<?= count($projectHistRows) ?>)</h2>
        <?php if (empty($projectHistRows)): ?>
            <div class="empty">
                <p><strong>No project stage transitions found.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($projectHistRows as $ph): ?>
                    <article class="card stack-s">
                        <div class="row-between">
                            <div>
                                <a href="<?= e(url('/admin/bookings/' . (int) $ph['booking_id'])) ?>">
                                    <strong><?= e($ph['reference_code']) ?></strong>
                                </a>
                                · <?= e($ph['client_name']) ?>
                            </div>
                            <span class="small muted"><?= e(datetime_short($ph['changed_at'])) ?></span>
                        </div>
                        <p class="small">
                            Stage: <code><?= e($ph['old_stage'] ?: 'initial') ?></code> → <strong><code><?= e($ph['new_stage']) ?></code></strong>
                            · By <?= e($ph['actor_name'] ?: 'Studio') ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section" aria-labelledby="proj-files-heading">
        <h2 class="h3" id="proj-files-heading">3b. File Storage, Backup &amp; Handover Log (<?= count($projectFileRows) ?>)</h2>
        <div class="stack">
            <?php foreach ($projectFileRows as $pf): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <div>
                            <a href="<?= e(url('/admin/projects/' . (int) $pf['id'] . '/files')) ?>">
                                <strong><?= e($pf['reference_code']) ?></strong>
                            </a>
                            · <?= e($pf['client_name']) ?>
                        </div>
                        <span class="tag tag-neutral"><?= e(ucfirst((string) $pf['stage'])) ?></span>
                    </div>
                    <p class="small"><strong>RAW folder:</strong> <code><?= e($pf['raw_folder'] ?: 'Not logged') ?></code></p>
                    <p class="small"><strong>EDITED folder:</strong> <code><?= e($pf['edited_folder'] ?: 'Not logged') ?></code></p>
                    <p class="small muted">
                        Local backup: <?= (int) $pf['local_backup_done'] === 1 ? '✓ (' . e(datetime_short($pf['local_backup_at'])) . ')' : 'No' ?>
                        · Cloud copy: <?= (int) $pf['cloud_copy_done'] === 1 ? '✓ (' . e(datetime_short($pf['cloud_copy_at'])) . ')' : 'No' ?>
                    </p>
                    <?php if (!empty($pf['released_at']) || !empty($pf['released_how'])): ?>
                        <p class="small">
                            <strong>Released:</strong> <?= e(date_short($pf['released_at'])) ?>
                            via <strong><?= e($pf['released_how']) ?></strong>
                            to <strong><?= e($pf['received_by'] ?: 'Client') ?></strong>
                            <?php if (!empty($pf['released_by_name'])): ?>
                                (by <?= e($pf['released_by_name']) ?>)
                            <?php endif; ?>
                            <?php if (!empty($pf['released_note'])): ?>
                                — <?= e($pf['released_note']) ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($tab === 'notifications'): ?>
    <section class="section" aria-labelledby="notif-tpl-heading">
        <h2 class="h3" id="notif-tpl-heading">4a. Client Template Notification Log (<?= count($messageLogRows) ?>)</h2>
        <?php if (empty($messageLogRows)): ?>
            <div class="empty">
                <p><strong>No template notifications logged yet.</strong></p>
            </div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($messageLogRows as $ml): ?>
                    <article class="card stack-s">
                        <div class="row-between">
                            <div>
                                <a href="<?= e(url('/admin/bookings/' . (int) $ml['booking_id'])) ?>">
                                    <strong><?= e($ml['reference_code']) ?></strong>
                                </a>
                                · <?= e($ml['client_name']) ?>
                            </div>
                            <span class="tag tag-neutral"><?= e($ml['channel'] === 'in_app' ? 'In-app chat' : 'Copied for Messenger') ?></span>
                        </div>
                        <p class="small muted">
                            Template: <code><?= e($ml['template_key'] ?: 'custom') ?></code> (<?= e(strtoupper((string) $ml['language'])) ?>)
                            · By <?= e($ml['actor_name'] ?: 'Studio') ?> · <?= e(datetime_short($ml['sent_at'])) ?>
                        </p>
                        <p class="small"><?= e($ml['body']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section" aria-labelledby="notif-sys-heading">
        <h2 class="h3" id="notif-sys-heading">4b. Automated In-Thread System Notices (<?= count($systemMsgRows) ?>)</h2>
        <div class="stack">
            <?php foreach ($systemMsgRows as $sm): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <div>
                            <a href="<?= e(url('/admin/messages/' . (int) $sm['conversation_id'])) ?>">
                                <strong>Conversation #<?= (int) $sm['conversation_id'] ?></strong>
                            </a>
                            <?php if (!empty($sm['reference_code'])): ?>
                                (<?= e($sm['reference_code']) ?> · <?= e($sm['client_name']) ?>)
                            <?php endif; ?>
                        </div>
                        <span class="small muted"><?= e(datetime_short($sm['created_at'])) ?></span>
                    </div>
                    <p class="small"><?= e($sm['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($tab === 'backups'): ?>
    <section class="section" aria-labelledby="bk-runs-heading">
        <div class="row-between">
            <h2 class="h3" id="bk-runs-heading">5. Backup &amp; Data Protection Logs (<?= count($backupRows) ?>)</h2>
            <a class="btn btn-small btn-outline no-print" href="<?= e(url('/admin/settings', ['tab' => 'backups'])) ?>">Manage backups (A20)</a>
        </div>
        <div class="stack section">
            <?php foreach ($backupRows as $br): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <strong><?= e($br['file_name']) ?></strong>
                        <span class="tag <?= !empty($br['cloud_copy_marked_at']) ? 'tag-confirmed' : 'tag-pending' ?>">
                            <?= !empty($br['cloud_copy_marked_at']) ? 'Cloud copy ✓' : 'Local only' ?>
                        </span>
                    </div>
                    <p class="small muted">
                        Created: <?= e(datetime_short($br['created_at'])) ?>
                        · Type: <?= e(ucfirst((string) $br['type'])) ?>
                        · Size: <?= e(number_format((int) $br['size_bytes'])) ?> bytes
                        · By: <?= e($br['created_by_name'] ?: 'CLI / Scheduled') ?>
                    </p>
                    <?php if (!empty($br['cloud_copy_marked_at'])): ?>
                        <p class="small muted">Cloud copy marked at: <?= e(datetime_short($br['cloud_copy_marked_at'])) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php else: ?>
    <section class="card stack section" aria-labelledby="err-log-heading">
        <h2 class="card-title" id="err-log-heading">6a. Application Error Log (<code>storage/logs/app.log</code>)</h2>
        <?php if (empty($errorLogLines)): ?>
            <p class="small muted">No runtime errors or uncaught exceptions recorded ✓</p>
        <?php else: ?>
            <pre class="small"><?= e(implode("\n", $errorLogLines)) ?></pre>
        <?php endif; ?>
    </section>

    <section class="card stack section" aria-labelledby="rl-log-heading">
        <h2 class="card-title" id="rl-log-heading">6b. Active Rate-Limit Counters (<code>rate_limits</code>)</h2>
        <?php if (empty($rateLimitRows)): ?>
            <p class="small muted">No active rate-limit throttling counters right now.</p>
        <?php else: ?>
            <div class="stack-s">
                <?php foreach ($rateLimitRows as $rl): ?>
                    <div class="card-row">
                        <code><?= e($rl['rl_key']) ?></code>
                        <span class="small muted">Hits: <strong><?= (int) $rl['hits'] ?></strong> (window <?= (int) $rl['window_start'] ?>)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
