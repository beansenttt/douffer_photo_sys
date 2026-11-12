<?php
/*
 * Screen A8: Confirmation and Official Receipt.
 * Used by both Admin (/admin/bookings/{id}/confirmation) and Client (/booking/{ref}/confirmation).
 * Print-friendly sheet (A4 @media print hides nav and buttons).
 */
$b = $doc['booking'];
$bid = (int) $b['id'];
$payments = $doc['payments'];
$studio = $doc['studio'];
$inclusions = $doc['inclusions'];
?>
<p class="eyebrow no-print">Screen A8 · <?= $isAdmin ? 'Confirmation &amp; Official Receipt' : 'Booking Confirmation' ?></p>

<?php if ($isAdmin): ?>
    <nav class="tabs no-print" aria-label="Document type">
        <a href="<?= e(url('/admin/bookings/' . $bid . '/confirmation?tab=confirmation')) ?>"<?= $tab === 'confirmation' ? ' aria-current="page"' : '' ?>>Booking confirmation</a>
        <a href="<?= e(url('/admin/bookings/' . $bid . '/confirmation?tab=receipt')) ?>"<?= $tab === 'receipt' ? ' aria-current="page"' : '' ?>>Official receipt (<?= count($payments) ?>)</a>
    </nav>
<?php endif; ?>

<?php if ($isAdmin && $tab === 'receipt'): ?>
    <?php if (empty($payments)): ?>
        <div class="empty">
            <strong>No verified payments yet.</strong>
            <p class="mb-0">An official receipt (`OR-YYYY-NNNN`) is issued automatically as soon as a payment is verified or recorded.</p>
        </div>
    <?php else: ?>
        <?php if (count($payments) > 1): ?>
            <div class="chips no-print mb-1" role="region" aria-label="Choose receipt">
                <?php foreach ($payments as $p):
                    $active = $selectedPayment && (int) $selectedPayment['id'] === (int) $p['id'];
                ?>
                    <a class="chip<?= $active ? ' is-active' : '' ?>"
                       href="<?= e(url('/admin/bookings/' . $bid . '/confirmation?tab=receipt&payment_id=' . (int) $p['id'])) ?>">
                        <?= e($p['receipt_no'] ?? ('#' . $p['id'])) ?> · <?= e(ucfirst($p['type'])) ?> (<?= e(peso($p['amount'])) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($selectedPayment): ?>
            <article class="card doc-sheet">
                <header class="doc-header">
                    <h2 class="doc-studio"><?= e(strtoupper($studio['name'])) ?></h2>
                    <p class="card-meta mb-0"><?= e($studio['address']) ?> · Tel. <?= e($studio['phone']) ?></p>
                    <div class="doc-badge">OFFICIAL RECEIPT</div>
                </header>

                <div class="card-row">
                    <span class="muted">Official Receipt No.</span>
                    <strong class="money"><?= e($selectedPayment['receipt_no']) ?></strong>
                </div>
                <div class="card-row">
                    <span class="muted">Booking Reference</span>
                    <strong><?= e($b['reference_code']) ?></strong>
                </div>
                <div class="card-row">
                    <span class="muted">Received from</span>
                    <strong><?= e($b['full_name']) ?></strong>
                </div>
                <div class="card-row">
                    <span class="muted">Event &amp; Date</span>
                    <span><?= e($b['event_type']) ?> · <?= e(date_short($b['event_date'])) ?></span>
                </div>
                <div class="card-row">
                    <span class="muted">Payment for</span>
                    <span><?= e(ucfirst($selectedPayment['type'])) ?> payment (<?= e($b['package_name']) ?>)</span>
                </div>
                <div class="card-row">
                    <span class="muted">Payment method</span>
                    <span><?= e(strtoupper($selectedPayment['method'])) ?><?= !empty($selectedPayment['gcash_reference_no']) ? ' · Ref. ' . e($selectedPayment['gcash_reference_no']) : '' ?></span>
                </div>
                <div class="card-row">
                    <span class="muted">Date verified</span>
                    <span><?= e(datetime_short($selectedPayment['verified_at'] ?? $selectedPayment['paid_at'])) ?></span>
                </div>
                <div class="card-row">
                    <span class="muted">Amount received</span>
                    <strong class="money price"><?= e(peso($selectedPayment['amount'])) ?></strong>
                </div>
                <div class="card-row">
                    <span class="muted">Remaining balance after payment</span>
                    <strong class="money"><?= e(peso($selectedPayment['balance_after'])) ?></strong>
                </div>
                <?php if (!empty($selectedPayment['verified_by_name'])): ?>
                    <div class="card-row">
                        <span class="muted">Verified / issued by</span>
                        <span><?= e($selectedPayment['verified_by_name']) ?></span>
                    </div>
                <?php endif; ?>

                <p class="doc-terms">
                    <strong>Terms:</strong> <?= e($doc['terms']) ?>
                </p>
            </article>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <article class="card doc-sheet">
        <header class="doc-header">
            <h2 class="doc-studio"><?= e(strtoupper($studio['name'])) ?></h2>
            <p class="card-meta mb-0"><?= e($studio['address']) ?> · Tel. <?= e($studio['phone']) ?></p>
            <div class="doc-badge">BOOKING CONFIRMATION</div>
        </header>

        <div class="card-row">
            <span class="muted">Ref. no.</span>
            <strong class="money"><?= e($b['reference_code']) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Status</span>
            <?= partial('partials/tag', ['class' => $b['status'] === 'completed' ? 'completed' : ($b['status'] === 'confirmed' ? 'confirmed' : 'pending'), 'label' => ucfirst($b['status'])]) ?>
        </div>
        <div class="card-row">
            <span class="muted">Client</span>
            <strong><?= e($b['full_name']) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Event</span>
            <span><?= e($b['event_type']) ?></span>
        </div>
        <div class="card-row">
            <span class="muted">Date / time</span>
            <span>
                <?= e(date_short($b['event_date'])) ?>
                <?php if (!empty($b['start_time'])): ?>
                    · <?= e(time12($b['start_time'])) ?><?= !empty($b['end_time']) ? ' – ' . e(time12($b['end_time'])) : '' ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="card-row">
            <span class="muted">Venue</span>
            <span><?= e($b['venue']) ?></span>
        </div>
        <div class="card-row">
            <span class="muted">Package</span>
            <strong><?= e($b['package_name']) ?></strong>
        </div>
        <?php if (!empty($inclusions)): ?>
            <div class="card-row doc-inclusions">
                <span class="muted">Inclusions</span>
                <ul class="incl mb-0">
                    <?php foreach ($inclusions as $inc): ?>
                        <li><?= e($inc) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="card-row">
            <span class="muted">Total</span>
            <strong class="money"><?= e(peso($b['total_amount'])) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Down payment received</span>
            <strong class="money"><?= e(peso($doc['down'])) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">GCash ref. no.</span>
            <span class="money"><?= e($doc['down_ref'] ?: '—') ?></span>
        </div>
        <div class="card-row">
            <span class="muted">Total paid to date</span>
            <strong class="money"><?= e(peso($doc['paid'])) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Balance</span>
            <strong class="money price"><?= e(peso($doc['balance'])) ?></strong>
        </div>

        <p class="doc-terms">
            <strong>Terms:</strong> <?= e($doc['terms']) ?>
        </p>
    </article>
<?php endif; ?>

<textarea id="messenger-copy-src" class="sr-only" readonly aria-hidden="true"><?= e($messengerText) ?></textarea>

<div class="btn-row mt-1 no-print">
    <button type="button" class="btn btn-primary" data-print><?= icon('print') ?> Print</button>
    <button type="button" class="btn btn-outline" data-copy="messenger-copy-src"><?= icon('copy') ?> Copy for Messenger</button>
</div>

<?php if ($isAdmin): ?>
    <div class="btn-row mt-1 no-print">
        <a class="btn btn-small" href="<?= e(url('/admin/bookings/' . $bid)) ?>">Back to booking details</a>
        <a class="btn btn-small" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates')) ?>">Ready-to-send templates (A12)</a>
    </div>
<?php endif; ?>
