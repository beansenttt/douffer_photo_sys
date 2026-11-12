<?php
/*
 * Screen A19: Messages Inbox (/admin/messages).
 * Vars: $filter, $q, $counts, $conversations
 */
$chips = [
    ['all', 'All', (int) ($counts['all'] ?? 0)],
    ['unread', 'Unread', (int) ($counts['unread'] ?? 0)],
    ['open', 'Open', (int) ($counts['open'] ?? 0)],
    ['closed', 'Closed', (int) ($counts['closed'] ?? 0)],
    ['inquiries', 'Inquiries', (int) ($counts['inquiries'] ?? 0)],
];
?>
<p class="eyebrow">Screen A19 · Messages Inbox</p>

<form method="get" action="<?= e(url('/admin/messages')) ?>" class="search-row mb-1" role="search">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <div>
        <label for="msg-q" class="sr-only">Search by client name, mobile, or reference number</label>
        <input type="search" id="msg-q" name="q" value="<?= e($q) ?>"
               placeholder="Search name, mobile, ref. no., or message…">
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('search') ?> Search</button>
</form>

<nav class="chips mb-1" aria-label="Filter conversations">
    <?php foreach ($chips as [$key, $label, $cnt]):
        $href = url('/admin/messages', array_filter([
            'filter' => $key === 'all' ? null : $key,
            'q'      => $q !== '' ? $q : null,
        ]));
    ?>
        <a class="chip<?= $filter === $key ? ' is-active' : '' ?>"
           href="<?= e($href) ?>"<?= $filter === $key ? ' aria-current="true"' : '' ?>>
            <span><?= e($label) ?></span>
            <span class="chip-count">(<?= $cnt ?>)</span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (empty($conversations)): ?>
    <div class="empty">
        <p class="mb-0">
            <strong>No conversations found.</strong>
            <?= $q !== '' || $filter !== 'all' ? 'Try clearing your search or filter.' : 'Client inquiries and booking threads will appear here.' ?>
        </p>
    </div>
<?php else: ?>
    <div class="stack" aria-label="Conversations list">
        <?php foreach ($conversations as $c):
            $cid = (int) $c['id'];
            $unreadCnt = (int) ($c['unread_count'] ?? 0);
            $isClosed = (string) ($c['status'] ?? 'open') === 'closed';
            $lastSender = (string) ($c['last_message_sender'] ?? '');
            $prefix = match ($lastSender) {
                'admin'  => 'You: ',
                'system' => 'System: ',
                'client' => 'Client: ',
                default  => '',
            };
            $lastBody = trim((string) ($c['last_message_body'] ?? ''));
            if ($lastBody === '' && !empty($c['last_message_attachment'])) {
                $lastBody = '[Photo attachment]';
            } elseif ($lastBody === '') {
                $lastBody = 'No messages yet';
            }
            $snippet = mb_strlen($lastBody) > 85 ? mb_substr($lastBody, 0, 82) . '…' : $lastBody;
        ?>
            <a class="card conv-card<?= $unreadCnt > 0 ? ' is-unread' : '' ?>"
               href="<?= e(url('/admin/messages/' . $cid)) ?>">
                <div class="row-between">
                    <div>
                        <strong class="card-title"><?= e($c['display_name']) ?></strong>
                        <?php if (!empty($c['display_mobile'])): ?>
                            <span class="small muted"> · <?= e(format_mobile($c['display_mobile'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <?php if ($unreadCnt > 0): ?>
                            <?= partial('partials/tag', ['class' => 'overdue', 'label' => $unreadCnt . ' unread']) ?>
                        <?php endif; ?>
                        <?php if ($isClosed): ?>
                            <?= partial('partials/tag', ['class' => 'cancelled', 'label' => 'Closed']) ?>
                        <?php else: ?>
                            <?= partial('partials/tag', ['class' => 'ok', 'label' => 'Open']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-meta mt-0">
                    <?php if (!empty($c['reference_code'])): ?>
                        <strong><?= e($c['reference_code']) ?></strong>
                        · <?= e($c['event_type']) ?> (<?= e(date_short($c['event_date'])) ?>)
                        <?php if (!empty($c['booking_status'])): ?>
                            · <?= partial('partials/tag', ['class' => (string) $c['booking_status'], 'label' => ucfirst((string) $c['booking_status'])]) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= partial('partials/tag', ['class' => 'pending', 'label' => 'Pre-booking inquiry']) ?>
                    <?php endif; ?>
                </div>

                <div class="row-between mt-1">
                    <span class="conv-snippet <?= $unreadCnt > 0 ? 'strong' : 'muted' ?>">
                        <?= e($prefix . $snippet) ?>
                    </span>
                    <time class="small muted"><?= e(datetime_short((string) ($c['last_message_created_at'] ?? $c['last_message_at'] ?? $c['created_at']))) ?></time>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
