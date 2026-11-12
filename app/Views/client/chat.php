<?php
/*
 * Screen C6: Client Chat (in-app messaging with the studio).
 * Modes:
 *  - 'start': Pre-booking inquiry form + links to unlocked booking/inquiry chats
 *  - 'thread': Live two-way conversation thread with polling & image attachments
 */
$mode = $mode ?? 'start';
?>
<p class="eyebrow">Screen C6 · Client Chat</p>

<?php if ($mode === 'start'): ?>
    <?php if (!empty($ownedList)): ?>
        <section class="card mb-1" aria-labelledby="active-chats-title">
            <h2 class="card-title" id="active-chats-title">Your active conversations</h2>
            <p class="card-meta">Tap a conversation below to continue chatting with the studio.</p>
            <div class="stack mt-1">
                <?php foreach ($ownedList as $oc):
                    $ocHref = !empty($oc['reference_code'])
                        ? url('/booking/' . $oc['reference_code'] . '/chat')
                        : url('/chat', ['conv' => (int) $oc['id']]);
                ?>
                    <a class="card" href="<?= e($ocHref) ?>">
                        <div class="row-between">
                            <strong><?= e($oc['display_name']) ?></strong>
                            <?php if (!empty($oc['reference_code'])): ?>
                                <?= partial('partials/tag', ['class' => 'info', 'label' => $oc['reference_code']]) ?>
                            <?php else: ?>
                                <?= partial('partials/tag', ['class' => 'pending', 'label' => 'Inquiry']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="small muted">
                            <?= !empty($oc['event_type']) ? e($oc['event_type']) . ' · ' . e(date_short($oc['event_date'])) : 'Pre-booking inquiry' ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="start-chat-title">
        <h2 class="h2" id="start-chat-title">Ask the studio a question</h2>
        <p class="card-meta">Have a question about packages, dates, or other services before booking? Start a direct chat below — no account needed.</p>

        <form method="post" action="<?= e(url('/chat/start')) ?>" class="stack mt-1" novalidate data-once>
            <?= csrf_field() ?>

            <div class="sr-only" aria-hidden="true">
                <label for="hp_website">Website (leave blank)</label>
                <input type="text" id="hp_website" name="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="field">
                <label for="guest_name">Your full name <span class="req">*</span></label>
                <input type="text" id="guest_name" name="guest_name" maxlength="120" required
                       value="<?= e(old('guest_name')) ?>" placeholder="e.g. Ana Villanueva"<?= invalid_attr('guest_name') ?>>
                <?= error_html('guest_name') ?>
            </div>

            <div class="field">
                <label for="guest_mobile">Mobile number <span class="req">*</span></label>
                <input type="tel" id="guest_mobile" name="guest_mobile" inputmode="tel" maxlength="20" required
                       value="<?= e(old('guest_mobile')) ?>" placeholder="0917 123 4567"<?= invalid_attr('guest_mobile') ?>>
                <p class="hint">Used so the studio can link your inquiry if you book later.</p>
                <?= error_html('guest_mobile') ?>
            </div>

            <div class="field">
                <label for="body">Your message <span class="req">*</span></label>
                <textarea id="body" name="body" rows="3" maxlength="1000" required
                          placeholder="Ask about availability, packages, photobooth, makeup artist, or custom requests…"<?= invalid_attr('body') ?>><?= e(old('body', $prefillBody ?? '')) ?></textarea>
                <?= error_html('body') ?>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="privacy_consent" value="1" required<?= old('privacy_consent') ? ' checked' : '' ?><?= invalid_attr('privacy_consent') ?>>
                    <span>I agree to the <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">Data Privacy Notice (RA 10173)</a> so Douffer Photography can respond to my inquiry. <span class="req">*</span></span>
                </label>
                <?= error_html('privacy_consent') ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('chat') ?> Start chat</button>
        </form>
    </section>

    <section class="card section" aria-labelledby="existing-booking-chat-title">
        <h3 class="card-title" id="existing-booking-chat-title">Already have a booking?</h3>
        <p class="card-meta">Enter your booking reference number and mobile number to open your booking's conversation thread.</p>
        <a class="btn btn-outline btn-block mt-1" href="<?= e(url('/booking')) ?>"><?= icon('list') ?> Look up my booking</a>
    </section>

<?php else:
    $convId = (int) $conv['id'];
    $isClosed = (string) ($conv['status'] ?? 'open') === 'closed';
    $lastMsgId = 0;
    foreach ($messages as $m) {
        if ((int) $m['id'] > $lastMsgId) {
            $lastMsgId = (int) $m['id'];
        }
    }
    $postAction = url('/chat/' . $convId . '/messages');
    $apiUrl = url('/api/conversations/' . $convId . '/messages');
?>
    <section class="card chat-header-card">
        <div class="row-between">
            <div>
                <?php if (!empty($conv['reference_code'])): ?>
                    <h2 class="card-title"><?= e($conv['event_type']) ?> · <?= e($conv['package_name'] ?? 'Package') ?></h2>
                    <div class="card-meta">
                        Ref. <strong><?= e($conv['reference_code']) ?></strong> · <?= e(date_short($conv['event_date'])) ?>
                    </div>
                <?php else: ?>
                    <h2 class="card-title">Pre-booking Inquiry</h2>
                    <div class="card-meta">
                        <?= e($conv['display_name']) ?><?= !empty($conv['display_mobile']) ? ' · ' . e(format_mobile($conv['display_mobile'])) : '' ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stack-right">
                <?php if ($isClosed): ?>
                    <?= partial('partials/tag', ['class' => 'cancelled', 'label' => 'Closed']) ?>
                <?php else: ?>
                    <?= partial('partials/tag', ['class' => 'ok', 'label' => 'Studio Chat']) ?>
                <?php endif; ?>
                <?php if (!empty($conv['booking_status'])): ?>
                    <?= partial('partials/tag', ['class' => (string) $conv['booking_status'], 'label' => ucfirst((string) $conv['booking_status'])]) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($token)):
            $fullLink = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/chat/t/' . $token);
        ?>
            <div class="chat-token-box mt-1">
                <span class="small muted">Bookmark your private inquiry link:</span>
                <div class="input-group mt-0">
                    <input type="text" id="inquiry-link" value="<?= e($fullLink) ?>" readonly aria-label="Your private inquiry chat link">
                    <button type="button" class="btn btn-outline btn-small" data-copy="inquiry-link"><?= icon('copy') ?> Copy</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="hub-links mt-1">
            <?php if (!empty($conv['reference_code'])): ?>
                <a class="link-like small" href="<?= e(url('/booking/' . $conv['reference_code'])) ?>">View booking status (C5)</a>
                <span class="muted">·</span>
            <?php endif; ?>
            <a class="link-like small" href="<?= e(url('/chat', ['new' => 1])) ?>">+ Start new inquiry</a>
        </div>
    </section>

    <section class="chat-shell mt-1" aria-label="Conversation messages">
        <div class="chat-thread" id="chat-thread"
             data-chat-thread
             data-conv-id="<?= $convId ?>"
             data-side="client"
             data-last-id="<?= $lastMsgId ?>"
             data-seen-id="<?= (int) $seenUpToId ?>"
             data-api-url="<?= e($apiUrl) ?>"
             data-token="<?= e((string) ($token ?? '')) ?>"
             aria-live="polite"
             aria-relevant="additions">
            <?php if (empty($messages)): ?>
                <div class="empty" data-chat-empty>
                    <p class="mb-0"><strong>No messages yet.</strong> Send a message below and the studio will reply here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m):
                    $mid = (int) $m['id'];
                    $sType = (string) $m['sender_type'];
                    $isOwn = $sType === 'client';
                    $attUrl = $m['attachment_url']
                        ? $m['attachment_url'] . (!empty($token) ? '?token=' . rawurlencode((string) $token) : '')
                        : null;
                ?>
                    <?php if ($sType === 'system'): ?>
                        <div class="chat-system" data-msg-id="<?= $mid ?>">
                            <span><?= e($m['body']) ?></span>
                            <time class="chat-time"><?= e($m['created_label']) ?></time>
                        </div>
                    <?php else: ?>
                        <article class="chat-msg <?= $isOwn ? 'is-own' : 'is-other' ?>" data-msg-id="<?= $mid ?>" data-sender="<?= e($sType) ?>">
                            <div class="chat-bubble">
                                <div class="chat-sender"><?= e($isOwn ? 'You' : $m['sender_label']) ?></div>
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

        <?php if ($isClosed): ?>
            <div class="alert alert-warning mt-1">
                <?= icon('alert') ?>
                <div>
                    <strong>This conversation has been closed by the studio.</strong>
                    <p class="mb-0">Need more help? <a class="link-like" href="<?= e(url('/chat', ['new' => 1])) ?>">Start a new inquiry</a>.</p>
                </div>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e($postAction) ?>" enctype="multipart/form-data"
                  class="chat-composer" id="chat-form" data-chat-form novalidate>
                <?= csrf_field() ?>
                <?php if (!empty($token)): ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                <?php endif; ?>
                <input type="hidden" name="as" value="client">

                <div class="field">
                    <label for="chat-body" class="sr-only">Message</label>
                    <textarea id="chat-body" name="body" rows="2" maxlength="1000"
                              placeholder="Write a message… (max 1,000 chars)"<?= invalid_attr('body') ?>><?= e(old('body')) ?></textarea>
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
                        <?= icon('chat') ?> Send
                    </button>
                </div>
                <div class="receipt-preview" id="receipt-preview" hidden>
                    <img id="receipt-img" alt="Preview of attached photo" width="72" height="72">
                    <p class="small muted mb-0" id="receipt-info"></p>
                </div>
                <?= error_html('attachment') ?>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
