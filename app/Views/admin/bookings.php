<?php
/*
 * Screen A4: Bookings Ledger.
 * Search by client name, date, mobile, event type or reference number.
 * Filter chips: All · Pending · Confirmed · Balance due · Completed · Archived.
 */
$filters = [
    ['all', 'All'],
    ['pending', 'Pending'],
    ['confirmed', 'Confirmed'],
    ['balance_due', 'Balance due'],
    ['completed', 'Completed'],
    ['archived', 'Archived'],
];
?>
<p class="eyebrow">Screen A4 · Bookings Ledger</p>

<form method="get" action="<?= e(url('/admin/bookings')) ?>" class="search-form" role="search">
    <?php if ($filter !== 'all'): ?>
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <?php endif; ?>
    <label for="ledger-q" class="sr-only">Search by name, date or reference number</label>
    <div class="search-row">
        <input type="search" id="ledger-q" name="q" value="<?= e($q) ?>" placeholder="Name, date (YYYY-MM-DD) or ref. no.">
        <button type="submit" class="btn btn-primary"><?= icon('search') ?> Search</button>
    </div>
</form>

<nav class="chips mt-1" aria-label="Filter bookings">
    <?php foreach ($filters as [$key, $label]):
        $href = '/admin/bookings?filter=' . $key . ($q !== '' ? '&q=' . urlencode($q) : '');
        $active = $filter === $key;
    ?>
        <a class="chip<?= $active ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $active ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="mt-1" aria-label="Bookings list">
    <?php if (empty($bookings)): ?>
        <div class="empty">
            <strong>No bookings found.</strong>
            <p class="mb-0">Try clearing the search or choosing another filter chip.</p>
        </div>
    <?php else: ?>
        <?php foreach ($bookings as $b):
            $bid = (int) $b['id'];
            $status = (string) $b['status'];
            $bal = (float) $b['balance_amount'];
            $isArchived = (int) ($b['is_archived'] ?? 0) === 1;

            if ($isArchived) {
                $tagClass = 'archived';
                $tagLabel = 'Archived';
            } elseif ($filter === 'balance_due' || ($status === 'confirmed' && $bal > 0)) {
                $tagClass = 'confirmed';
                $tagLabel = 'Confirmed';
            } elseif ($status === 'completed') {
                $tagClass = 'completed';
                $tagLabel = 'Completed';
            } elseif ($status === 'cancelled') {
                $tagClass = 'cancelled';
                $tagLabel = 'Cancelled';
            } else {
                $tagClass = 'pending';
                $tagLabel = 'Pending';
            }
        ?>
            <a class="card" href="<?= e(url('/admin/bookings/' . $bid)) ?>">
                <div class="card-row">
                    <div>
                        <h2 class="card-title"><?= e($b['full_name']) ?> – <?= e($b['event_type']) ?></h2>
                        <div class="card-meta"><?= e(date_short($b['event_date'])) ?> · <?= e($b['package_name']) ?> · <?= e($b['reference_code']) ?></div>
                        <div class="card-meta">
                            <?php if ($status === 'pending'): ?>
                                Down payment waiting for check (<?= e(peso($b['down_payment_due'])) ?>)
                            <?php elseif ($status === 'completed' && $bal <= 0): ?>
                                Fully paid · output released
                            <?php else: ?>
                                Balance: <strong class="money"><?= e(peso($bal)) ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stack-right">
                        <?= partial('partials/tag', ['class' => $tagClass, 'label' => $tagLabel]) ?>
                        <?php if ($status === 'confirmed' && $bal > 0): ?>
                            <?= partial('partials/tag', ['class' => 'balance', 'label' => 'Balance due']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
