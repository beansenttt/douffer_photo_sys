<?php
declare(strict_types=1);

/**
 * Screen A11: Files & Handover Log.
 * Records folder paths (BR-10: no media upload), backup checkboxes, release warnings, and handover metadata.
 */
$pid = (int) $project['id'];
$bid = (int) $project['booking_id'];
$isReleased = ($project['stage'] === 'released');
$hasWarnings = empty($project['cloud_copy_done']) || !empty($project['has_unpaid_balance']);
?>
<p class="eyebrow">Screen A11 · Files &amp; Handover Log</p>

<!-- Project Summary Card -->
<article class="card">
    <div class="card-row">
        <div>
            <h2 class="card-title"><?= e($project['client_name']) ?> – <?= e($project['event_type']) ?></h2>
            <div class="card-meta">
                <?= e(date_long($project['event_date'])) ?> · <?= e($project['package_name']) ?>
            </div>
            <div class="card-meta">
                Ref. <strong><?= e($project['reference_code']) ?></strong> · Due: <strong><?= e(date_short($project['due_date'])) ?></strong>
            </div>
        </div>
        <div class="stack-right">
            <?= partial('partials/tag', ['class' => $isReleased ? 'released' : 'info', 'label' => ucfirst($project['stage'])]) ?>
            <?php if (!empty($project['is_overdue'])): ?>
                <?= partial('partials/tag', ['class' => 'overdue', 'label' => 'Overdue']) ?>
            <?php endif; ?>
            <?php if (!empty($project['has_unpaid_balance'])): ?>
                <?= partial('partials/tag', ['class' => 'balance', 'label' => peso($project['balance_amount']) . ' due']) ?>
            <?php endif; ?>
        </div>
    </div>
</article>

<!-- Files & Handover Form -->
<section class="card mt-1" aria-label="Files and storage locations">
    <h3 class="card-title">Storage Locations (BR-10)</h3>
    <p class="card-meta">Record directory paths or cloud URLs. Media files are kept on studio storage and are not uploaded here.</p>

    <form method="post" action="<?= e(url('/admin/projects/' . $pid . '/files')) ?>" data-once>
        <?= csrf_field() ?>

        <div class="field">
            <label for="raw-folder">RAW files folder path</label>
            <input type="text" id="raw-folder" name="raw_folder" value="<?= e(old('raw_folder', $project['raw_folder'] ?? '')) ?>" placeholder="e.g. D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW">
            <p class="hint">Local hard drive path where raw camera files are archived.</p>
        </div>

        <div class="field mt-1">
            <label for="edited-folder">Edited files / delivery link folder path</label>
            <input type="text" id="edited-folder" name="edited_folder" value="<?= e(old('edited_folder', $project['edited_folder'] ?? '')) ?>" placeholder="e.g. D:/Douffer/.../EDITED or https://drive.google.com/...">
            <p class="hint">Location of final JPEG/MP4 outputs or cloud drive sharing URL.</p>
        </div>

        <h3 class="card-title mt-1">Backup Tracking</h3>
        <div class="stack">
            <label class="check">
                <input type="checkbox" name="local_backup_done" value="1"<?= !empty($project['local_backup_done']) ? ' checked' : '' ?>>
                <span>
                    <strong>Local backup done</strong> (external HDD)
                    <?php if (!empty($project['local_backup_at'])): ?>
                        <span class="muted small">· Stamped <?= e(datetime_short($project['local_backup_at'])) ?></span>
                    <?php endif; ?>
                </span>
            </label>

            <label class="check">
                <input type="checkbox" name="cloud_copy_done" value="1"<?= !empty($project['cloud_copy_done']) ? ' checked' : '' ?>>
                <span>
                    <strong>Cloud copy done</strong> (Google Drive / OneDrive)
                    <?php if (!empty($project['cloud_copy_at'])): ?>
                        <span class="muted small">· Stamped <?= e(datetime_short($project['cloud_copy_at'])) ?></span>
                    <?php endif; ?>
                </span>
            </label>
        </div>

        <h3 class="card-title mt-1">Handover &amp; Release</h3>
        <div class="field">
            <label for="released-how">Release method</label>
            <select id="released-how" name="released_how">
                <option value="pickup"<?= ($project['released_how'] ?? 'pickup') === 'pickup' ? ' selected' : '' ?>>Studio pick-up (USB flash drive / Photo prints)</option>
                <option value="messenger_link"<?= ($project['released_how'] ?? '') === 'messenger_link' ? ' selected' : '' ?>>Messenger cloud link sent</option>
                <option value="other"<?= ($project['released_how'] ?? '') === 'other' ? ' selected' : '' ?>>Other channel / Courier delivery</option>
            </select>
        </div>

        <div class="grid-2 mt-1">
            <div class="field">
                <label for="released-at">Release date</label>
                <input type="date" id="released-at" name="released_at" value="<?= e(old('released_at', $project['released_at'] ?: \App\Core\Clock::today())) ?>">
            </div>
            <div class="field">
                <label for="received-by">Received by</label>
                <input type="text" id="received-by" name="received_by" value="<?= e(old('received_by', $project['received_by'] ?: $project['client_name'])) ?>" maxlength="120">
            </div>
        </div>

        <div class="field mt-1">
            <label for="released-note">Handover notes (optional)</label>
            <input type="text" id="released-note" name="released_note" value="<?= e(old('released_note', $project['released_note'] ?? '')) ?>" maxlength="255" placeholder="e.g. 1x Wooden Box USB + 20 4R prints handed over">
        </div>

        <!-- Release Advisory Warnings -->
        <?php if ($hasWarnings): ?>
            <div class="mt-1">
                <?php if (empty($project['cloud_copy_done'])): ?>
                    <div class="alert alert-warning">
                        <?= icon('alert') ?>
                        <div>
                            <strong>No cloud copy yet. Release anyway?</strong>
                            <p class="mb-0">A cloud backup has not been completed. Releasing without a cloud copy increases data loss risk.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($project['has_unpaid_balance'])): ?>
                    <div class="alert alert-danger">
                        <?= icon('alert') ?>
                        <div>
                            <strong>Balance not fully paid (<?= peso($project['balance_amount']) ?> remaining).</strong>
                            <p class="mb-0">An unpaid balance remains. Marking released will keep booking in confirmed status until fully settled.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="confirm_release_override" value="1">
                        <span><strong>Confirm release override:</strong> I confirm releasing deliverables despite missing cloud backup or unpaid balance.</span>
                    </label>
                </div>
            </div>
        <?php endif; ?>

        <!-- Submission Actions -->
        <div class="btn-row mt-1">
            <button type="submit" name="mark_released" value="0" class="btn btn-outline">
                <?= icon('check') ?> Save storage details
            </button>
            <button type="submit" name="mark_released" value="1" class="btn btn-primary">
                <?= icon('check') ?> <?= $isReleased ? 'Update release log' : 'Mark as released' ?>
            </button>
        </div>
    </form>
</section>

<!-- Timeline / History Card -->
<section class="card mt-1" aria-label="Project stage history">
    <h3 class="card-title">Stage history</h3>
    <?php if (empty($history)): ?>
        <p class="muted mb-0">No stage history recorded yet.</p>
    <?php else: ?>
        <ul class="timeline mt-1">
            <?php foreach ($history as $h): ?>
                <li>
                    <strong><?= e(ucfirst($h['old_stage'] ?? 'Initial')) ?> → <?= e(ucfirst($h['new_stage'])) ?></strong>
                    <div class="small muted">
                        <?= e(datetime_short($h['changed_at'])) ?>
                        <?= !empty($h['user_name']) ? ' · by ' . e($h['user_name']) : '' ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="mt-1 small">
    <a class="link-like" href="<?= e(url('/admin/projects')) ?>">← Back to Project Tracker (A10)</a>
    <span class="muted">·</span>
    <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid)) ?>">View booking hub (A6)</a>
</div>
