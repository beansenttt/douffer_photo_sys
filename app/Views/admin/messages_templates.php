<?php
declare(strict_types=1);

/**
 * Screen A12: Client Message Templates.
 * Bilingual template selector (TL/EN), dynamic placeholder substitution, editable preview,
 * Copy to clipboard (Messenger), Mark as sent (message_log), Send in app chat (conversations/messages).
 */
$bid = (int) $booking['id'];
?>
<p class="eyebrow">Screen A12 · Client Message Templates</p>

<!-- Client & Booking Header -->
<article class="card">
    <div class="card-row">
        <div>
            <h2 class="card-title"><?= e($booking['full_name']) ?> – <?= e($booking['event_type']) ?></h2>
            <div class="card-meta">
                <?= e(date_long($booking['event_date'])) ?> · Ref. <strong><?= e($booking['reference_code']) ?></strong>
            </div>
        </div>
        <div class="stack-right">
            <?= partial('partials/tag', ['class' => $booking['status'] === 'confirmed' ? 'confirmed' : 'info', 'label' => ucfirst((string) $booking['status'])]) ?>
            <?php if ($balance > 0): ?>
                <?= partial('partials/tag', ['class' => 'balance', 'label' => peso($balance) . ' due']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="hub-links mt-1">
        <a class="link-like small" href="<?= e(url('/admin/bookings/' . $bid . '/chat')) ?>"><?= icon('chat') ?> Open In-App Chat (A19)</a>
        <span class="muted">·</span>
        <a class="link-like small" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Booking Details (A6)</a>
    </div>
</article>

<!-- Language Switcher Chips -->
<div class="mt-1">
    <p class="eyebrow mb-0">Language</p>
    <div class="chips mt-0" role="navigation" aria-label="Template language">
        <a class="chip<?= $lang === 'tl' ? ' is-active' : '' ?>" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates?template=' . urlencode($activeKey) . '&lang=tl')) ?>"<?= $lang === 'tl' ? ' aria-current="true"' : '' ?>>
            Filipino (Tagalog)
        </a>
        <a class="chip<?= $lang === 'en' ? ' is-active' : '' ?>" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates?template=' . urlencode($activeKey) . '&lang=en')) ?>"<?= $lang === 'en' ? ' aria-current="true"' : '' ?>>
            English
        </a>
    </div>
</div>

<!-- Template Switcher Chips -->
<div class="mt-1">
    <p class="eyebrow mb-0">Template</p>
    <div class="chips mt-0" role="navigation" aria-label="Message template selection">
        <?php foreach ($templates as $t):
            $isActive = ($t['template_key'] === $activeKey);
        ?>
            <a class="chip<?= $isActive ? ' is-active' : '' ?>" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates?template=' . urlencode($t['template_key']) . '&lang=' . urlencode($lang))) ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
                <?= e($t['title']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Message Editor & Dispatch Form -->
<section class="card mt-1" aria-label="Message composer">
    <h3 class="card-title"><?= e($interpolated['title']) ?></h3>
    <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/messages/templates/send')) ?>" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="template_key" value="<?= e($activeKey) ?>">
        <input type="hidden" name="language" value="<?= e($lang) ?>">

        <div class="field">
            <label for="message-body">Message preview (editable before dispatch)</label>
            <textarea id="message-body" name="body" rows="7" required><?= e($interpolated['body']) ?></textarea>
            <p class="hint">Placeholders like [Client name], [Event], [Event date], [Balance], and [Studio] are pre-filled.</p>
        </div>

        <div class="btn-row mt-1">
            <button type="button" class="btn btn-outline" data-copy="message-body">
                <?= icon('copy') ?> Copy message
            </button>
            <button type="submit" name="action_type" value="mark_sent" class="btn btn-outline">
                <?= icon('check') ?> Mark as sent
            </button>
        </div>

        <div class="mt-1">
            <button type="submit" name="action_type" value="send_chat" class="btn btn-primary btn-block">
                <?= icon('chat') ?> Send in app chat
            </button>
        </div>
    </form>
</section>

<!-- Sent Message History -->
<section class="card mt-1" aria-label="Sent message history">
    <h3 class="card-title">Dispatch history (<?= count($sentHistory) ?>)</h3>
    <?php if (empty($sentHistory)): ?>
        <p class="muted mb-0">No template messages sent yet for this booking.</p>
    <?php else: ?>
        <div class="stack mt-1">
            <?php foreach ($sentHistory as $sh):
                $isChat = ($sh['channel'] === 'in_app');
            ?>
                <article class="card-row">
                    <div>
                        <strong><?= e($sh['template_title'] ?? ucfirst(str_replace('_', ' ', (string) $sh['template_key']))) ?></strong>
                        <?= partial('partials/tag', ['class' => $isChat ? 'ok' : 'info', 'label' => $isChat ? 'In-app chat' : 'Copy (Messenger)']) ?>
                        <span class="small muted">· <?= strtoupper((string) $sh['language']) ?></span>
                        <p class="small mt-1 mb-0"><?= nl2br(e($sh['body'])) ?></p>
                        <div class="small muted mt-1">
                            <?= e(datetime_short($sh['sent_at'])) ?>
                            <?= !empty($sh['sent_by_name']) ? ' · by ' . e($sh['sent_by_name']) : '' ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="mt-1 small">
    <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid)) ?>">← Back to booking hub (A6)</a>
</div>
