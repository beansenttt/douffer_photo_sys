<?php
/*
 * Screen A19: Admin Message Thread (/admin/messages/{id}).
 * Vars: $conv, $messages, $seenUpToId, $quickReplies, $booking, $paid, $balance, $linkableBookings, $linkableClients
 */
$convId = (int) $conv['id'];
$isClosed = (string) ($conv['status'] ?? 'open') === 'closed';
$lastMsgId = 0;
$lastClientOrAdminText = '';
foreach ($messages as $m) {
    if ((int) $m['id'] > $lastMsgId) {
        $lastMsgId = (int) $m['id'];
    }
    if ((string) $m['body'] !== '') {
        $lastClientOrAdminText = (string) $m['body'];
    }
}
$apiUrl = url('/api/conversations/' . $convId . '/messages');
$postAction = url('/admin/messages/' . $convId . '/messages');
?>
<p class="eyebrow">Screen A19 · Conversation Thread #<?= $convId ?></p>

<?php if ($booking):
    $bid = (int) $booking['id'];
    $bStatus = (string) $booking['status'];
?>
    <section class="card" aria-label="Booking summary card">
        <div class="row-between">
            <div>
                <h2 class="card-title"><?= e($booking['full_name']) ?> – <?= e($booking['event_type']) ?></h2>
                <div class="card-meta">
                    Ref. <strong><?= e($booking['reference_code']) ?></strong>
                    · <?= e(date_long($booking['event_date'])) ?>
                    · <?= e($booking['package_name']) ?>
                </div>
                <div class="card-meta">
                    Mobile: <strong><?= e(format_mobile($booking['mobile'])) ?></strong>
                    <?= !empty($booking['messenger_name']) ? ' · Messenger: ' . e($booking['messenger_name']) : '' ?>
                </div>
            </div>
            <div class="stack-right">
                <?= partial('partials/tag', ['class' => $bStatus, 'label' => ucfirst($bStatus)]) ?>
                <?php if ($isClosed): ?>
                    <?= partial('partials/tag', ['class' => 'cancelled', 'label' => 'Thread Closed']) ?>
                <?php else: ?>
                    <?= partial('partials/tag', ['class' => 'ok', 'label' => 'Thread Open']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-row mt-1">
            <span class="muted">Total: <strong class="money"><?= e(peso($booking['total_amount'])) ?></strong> · Paid: <strong class="money"><?= e(peso($paid)) ?></strong></span>
            <span>Balance: <strong class="money price"><?= e(peso($balance)) ?></strong></span>
        </div>

        <div class="hub-links mt-1 no-print">
            <a class="link-like small" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Booking Details (A6)</a>
            <span class="muted">·</span>
            <a class="link-like small" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates')) ?>">Templates (A12)</a>
            <span class="muted">·</span>
            <a class="link-like small" href="<?= e(url('/admin/messages/' . $convId . '/export')) ?>">Export (.txt)</a>
            <span class="muted">·</span>
            <a class="link-like small" href="#" data-print>Print thread</a>
        </div>
    </section>
<?php else: ?>
    <section class="card" aria-label="Pre-booking inquiry summary">
        <div class="row-between">
            <div>
                <h2 class="card-title"><?= e($conv['display_name']) ?></h2>
                <div class="card-meta">
                    Mobile: <strong><?= e(format_mobile($conv['display_mobile'])) ?></strong>
                    · Started <?= e(datetime_short((string) $conv['created_at'])) ?>
                </div>
            </div>
            <div class="stack-right">
                <?= partial('partials/tag', ['class' => 'pending', 'label' => 'Pre-booking inquiry']) ?>
                <?php if ($isClosed): ?>
                    <?= partial('partials/tag', ['class' => 'cancelled', 'label' => 'Closed']) ?>
                <?php else: ?>
                    <?= partial('partials/tag', ['class' => 'ok', 'label' => 'Open']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="hub-links mt-1 no-print">
            <a class="link-like small" href="#link-sheet" data-sheet-open="link-sheet">Link to booking or client</a>
            <span class="muted">·</span>
            <a class="link-like small" href="<?= e(url('/admin/bookings/new')) ?>">+ Create booking (A5)</a>
            <span class="muted">·</span>
            <a class="link-like small" href="<?= e(url('/admin/messages/' . $convId . '/export')) ?>">Export (.txt)</a>
            <span class="muted">·</span>
            <a class="link-like small" href="#" data-print>Print thread</a>
        </div>
    </section>
<?php endif; ?>

<div class="row-between mt-1 no-print">
    <form method="post" action="<?= e(url('/admin/messages/' . $convId . '/status')) ?>" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="status" value="<?= $isClosed ? 'open' : 'closed' ?>">
        <button type="submit" class="btn btn-outline btn-small">
            <?= $isClosed ? 'Reopen conversation' : 'Close conversation' ?>
        </button>
    </form>

    <?php if ($lastClientOrAdminText !== ''): ?>
        <textarea id="copy-messenger-buf" class="sr-only" readonly><?= e($lastClientOrAdminText) ?></textarea>
        <button type="button" class="btn btn-outline btn-small" data-copy="copy-messenger-buf">
            <?= icon('copy') ?> Copy for Messenger
        </button>
    <?php endif; ?>
</div>

<section class="chat-shell mt-1" aria-label="Conversation messages">
    <div class="chat-thread" id="chat-thread"
         data-chat-thread
         data-conv-id="<?= $convId ?>"
         data-side="admin"
         data-last-id="<?= $lastMsgId ?>"
         data-seen-id="<?= (int) $seenUpToId ?>"
         data-api-url="<?= e($apiUrl) ?>"
         aria-live="polite"
         aria-relevant="additions">
        <?php if (empty($messages)): ?>
            <div class="empty" data-chat-empty>
                <p class="mb-0"><strong>No messages in this conversation yet.</strong></p>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $m):
                $mid = (int) $m['id'];
                $sType = (string) $m['sender_type'];
                $isOwn = $sType === 'admin';
                $attUrl = $m['attachment_url'];
            ?>
                <?php if ($sType === 'system'): ?>
                    <div class="chat-system" data-msg-id="<?= $mid ?>">
                        <span><?= e($m['body']) ?></span>
                        <time class="chat-time"><?= e($m['created_label']) ?></time>
                    </div>
                <?php else: ?>
                    <article class="chat-msg <?= $isOwn ? 'is-own' : 'is-other' ?>" data-msg-id="<?= $mid ?>" data-sender="<?= e($sType) ?>">
                        <div class="chat-bubble">
                            <div class="chat-sender"><?= e($isOwn ? $m['sender_label'] : $conv['display_name']) ?></div>
                            <?php if ($m['body'] !== ''): ?>
                                <p class="chat-text"><?= nl2br(e($m['body'])) ?></p>
                            <?php endif; ?>
                            <?php if ($attUrl): ?>
                                <a class="chat-att-link" href="<?= e($attUrl) ?>" target="_blank" rel="noopener">
                                    <img class="chat-att-img" src="<?= e($attUrl) ?>" alt="Chat attachment" loading="lazy">
                                </a>
                            <?php endif; ?>
                            <div class="chat-meta">
                                <time class="chat-time"><?= e($m['created_label']) ?></time>
                                <?php if ($isOwn): ?>
                                    <span class="chat-receipt" data-msg-status="<?= $mid ?>">
                                        · <?= !empty($m['read_at']) ? 'Seen' : 'Sent' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($quickReplies)): ?>
        <div class="chat-quick-bar no-print" aria-label="Quick replies">
            <span class="small muted">Quick replies:</span>
            <div class="chips">
                <?php foreach ($quickReplies as $qr): ?>
                    <button type="button" class="chip" data-quick-reply="<?= e($qr['body']) ?>">
                        <?= e($qr['title']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($postAction) ?>" enctype="multipart/form-data"
          class="chat-composer no-print" id="chat-form" data-chat-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="as" value="admin">

        <div class="field">
            <label for="chat-body" class="sr-only">Reply message</label>
            <textarea id="chat-body" name="body" rows="2" maxlength="1000"
                      placeholder="Write a reply to <?= e($conv['display_name']) ?>… (max 1,000 chars)"<?= invalid_attr('body') ?>><?= e(old('body')) ?></textarea>
            <?= error_html('body') ?>
        </div>

        <div class="chat-composer-bar">
            <div class="chat-attach-wrap">
                <label for="receipt" class="btn btn-outline btn-small chat-attach-label">
                    <?= icon('camera') ?> <span>Add photo (≤ 2 MB)</span>
                </label>
                <input id="receipt" name="attachment" type="file" accept="image/jpeg,image/png,image/webp"
                       class="sr-only" data-compress data-chat-file>
            </div>
            <button type="submit" class="btn btn-primary" data-chat-send>
                <?= icon('chat') ?> Send reply
            </button>
        </div>
        <div class="receipt-preview" id="receipt-preview" hidden>
            <img id="receipt-img" alt="Preview of attached photo" width="72" height="72">
            <p class="small muted mb-0" id="receipt-info"></p>
        </div>
        <?= error_html('attachment') ?>
    </form>
</section>

<?php if (!$booking): ?>
    <div class="sheet no-print" id="link-sheet" role="dialog" aria-modal="true" aria-labelledby="link-sheet-title">
        <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
        <div class="sheet-panel">
            <h3 class="h3 mt-0" id="link-sheet-title">Link inquiry to booking or client</h3>
            <p class="card-meta">Connect this pre-booking inquiry thread to an existing booking or client record.</p>

            <form method="post" action="<?= e(url('/admin/messages/' . $convId . '/link')) ?>" class="stack mt-1" data-once>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="link_booking_id">Link to booking (recommended)</label>
                    <select id="link_booking_id" name="booking_id">
                        <option value="">— Select a booking —</option>
                        <?php foreach ($linkableBookings as $lb): ?>
                            <option value="<?= (int) $lb['id'] ?>"<?= (int) ($conv['booking_id'] ?? 0) === (int) $lb['id'] ? ' selected' : '' ?>>
                                <?= e($lb['reference_code']) ?> · <?= e($lb['full_name']) ?> (<?= e($lb['event_type']) ?>, <?= e($lb['event_date']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="link_client_id">Or link to client record only</label>
                    <select id="link_client_id" name="client_id">
                        <option value="">— Select a client —</option>
                        <?php foreach ($linkableClients as $lc): ?>
                            <option value="<?= (int) $lc['id'] ?>"<?= (int) ($conv['client_id'] ?? 0) === (int) $lc['id'] ? ' selected' : '' ?>>
                                <?= e($lc['full_name']) ?> (<?= e(format_mobile($lc['mobile'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sheet-actions">
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('check') ?> Save link</button>
                    <a class="btn btn-block" href="#" data-sheet-close>Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
