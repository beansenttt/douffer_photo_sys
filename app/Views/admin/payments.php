<?php
/*
 * Screen A7: Payments to Verify.
 * Manual GCash verification queue. Never auto-approves.
 * Flags duplicate GCash reference numbers (Risk 3 / §9.4) and warns when other
 * requests are waiting for the same date (§9.1).
 */
?>
<p class="eyebrow">Screen A7 · Verification queue</p>

<?php if (empty($queue)): ?>
    <div class="empty">
        <strong>No payments waiting.</strong>
        <p class="mb-0">When a client uploads a GCash screenshot, it will appear here for you to verify.</p>
    </div>
<?php else: ?>
    <?php foreach ($queue as $p):
        $pid = (int) $p['id'];
        $bid = (int) $p['booking_id'];
        $dups = $p['duplicates'] ?? [];
        $hasDup = !empty($dups);
        $otherPending = (int) ($p['other_pending_on_date'] ?? 0);
        $dateOpen = (bool) ($p['date_open'] ?? true);
    ?>
        <article class="card" id="payment-<?= $pid ?>">
            <div class="card-row">
                <div>
                    <h2 class="card-title">
                        <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid)) ?>"><?= e($p['full_name']) ?> – <?= e($p['event_type']) ?></a>
                    </h2>
                    <div class="card-meta">
                        <?= e($p['package_name']) ?> · <?= e(date_short($p['event_date'])) ?>
                        <?php if (!empty($p['start_time'])): ?> · <?= e(time12($p['start_time'])) ?><?php endif; ?>
                    </div>
                    <div class="card-meta">Ref. <?= e($p['reference_code']) ?> · Mobile: <?= e($p['mobile']) ?></div>
                </div>
                <div>
                    <?= partial('partials/tag', ['class' => 'pending', 'label' => 'To verify']) ?>
                </div>
            </div>

            <?php if (!$dateOpen): ?>
                <div class="alert alert-error mt-1" role="alert">
                    <?= icon('alert') ?>
                    <div>
                        <strong>Date is already full (<?= e(date_short($p['event_date'])) ?>).</strong>
                        <p class="mb-0">Maximum confirmed events reached or date is blocked. Reschedule the booking or reject this payment.</p>
                    </div>
                </div>
            <?php elseif ($otherPending > 0): ?>
                <div class="alert alert-warning mt-1" role="status">
                    <?= icon('alert') ?>
                    <div>
                        <strong><?= $otherPending ?> other <?= $otherPending === 1 ? 'request is' : 'requests are' ?> waiting for <?= e(date_short($p['event_date'])) ?>.</strong>
                        <p class="mb-0">First verified down payment locks the slot (BR-1).</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasDup): ?>
                <div class="alert alert-error mt-1" role="alert">
                    <?= icon('alert') ?>
                    <div>
                        <strong>Duplicate GCash reference number detected!</strong>
                        <p class="mb-0">
                            Ref. <code><?= e($p['gcash_reference_no']) ?></code> was already used on:
                            <?php foreach ($dups as $idx => $d): ?>
                                <?= $idx > 0 ? ', ' : '' ?>
                                <strong><?= e($d['reference_code']) ?></strong> (<?= e(peso($d['amount'])) ?>, <?= e($d['status']) ?>)
                            <?php endforeach; ?>.
                            Check your GCash app carefully before verifying.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="receipt-box mt-1">
                <?php if (!empty($p['receipt_path'])): ?>
                    <a class="receipt-zoom-trigger" href="#zoom-<?= $pid ?>" data-sheet-open="zoom-<?= $pid ?>">
                        <img class="receipt-thumb" src="<?= e(url('/files/receipt/' . $pid . '/thumb')) ?>" alt="GCash screenshot uploaded by <?= e($p['full_name']) ?>">
                        <span class="small link-like">Tap screenshot to zoom</span>
                    </a>
                <?php else: ?>
                    <div class="qr">No screenshot file</div>
                <?php endif; ?>

                <div class="receipt-meta">
                    <div class="card-row">
                        <span class="muted">GCash ref. no.</span>
                        <strong class="money"><?= e($p['gcash_reference_no'] ?: '—') ?></strong>
                    </div>
                    <div class="card-row">
                        <span class="muted">Amount entered</span>
                        <strong class="money price"><?= e(peso($p['amount'])) ?></strong>
                    </div>
                    <div class="card-row">
                        <span class="muted">Payment type</span>
                        <span><?= e(ucfirst($p['type'])) ?> payment · <?= e(strtoupper($p['method'])) ?></span>
                    </div>
                    <div class="card-row">
                        <span class="muted">Submitted</span>
                        <span><?= e(datetime_short($p['paid_at'] ?? $p['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <form method="post" action="<?= e(url('/admin/payments/' . $pid . '/verify')) ?>" class="mt-1" data-once>
                <?= csrf_field() ?>
                <?php if ($hasDup): ?>
                    <label class="check">
                        <input type="checkbox" name="ack_duplicate" value="1" required>
                        <span>I checked my GCash app and confirm this duplicate reference number (<code><?= e($p['gcash_reference_no']) ?></code>) is valid.</span>
                    </label>
                <?php endif; ?>

                <div class="btn-row">
                    <a class="btn btn-outline" href="#reject-<?= $pid ?>" data-sheet-open="reject-<?= $pid ?>">Reject</a>
                    <button type="submit" class="btn btn-primary"<?= !$dateOpen ? ' disabled' : '' ?>>
                        <?= icon('check') ?> Verify &amp; confirm
                    </button>
                </div>
            </form>
        </article>

        <?php if (!empty($p['receipt_path'])): ?>
            <div class="sheet" id="zoom-<?= $pid ?>" role="dialog" aria-modal="true" aria-labelledby="zoom-title-<?= $pid ?>">
                <a class="sheet-backdrop" href="#payment-<?= $pid ?>" data-sheet-close aria-label="Close"></a>
                <div class="sheet-panel">
                    <h3 class="h3 mt-0" id="zoom-title-<?= $pid ?>">GCash Screenshot · <?= e($p['full_name']) ?></h3>
                    <p class="card-meta">Ref. no.: <strong><?= e($p['gcash_reference_no']) ?></strong> · Amount: <strong><?= e(peso($p['amount'])) ?></strong></p>
                    <div class="zoom-img-wrap">
                        <img class="zoom-img" src="<?= e(url('/files/receipt/' . $pid)) ?>" alt="Full GCash screenshot for <?= e($p['full_name']) ?>">
                    </div>
                    <div class="sheet-actions">
                        <a class="btn btn-block" href="#payment-<?= $pid ?>" data-sheet-close>Close</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="sheet" id="reject-<?= $pid ?>" role="dialog" aria-modal="true" aria-labelledby="reject-title-<?= $pid ?>">
            <a class="sheet-backdrop" href="#payment-<?= $pid ?>" data-sheet-close aria-label="Close"></a>
            <div class="sheet-panel">
                <h3 class="h3 mt-0" id="reject-title-<?= $pid ?>">Reject payment · <?= e($p['full_name']) ?></h3>
                <p class="card-meta">The booking stays pending and the client can upload a new screenshot from their booking status page.</p>
                <form method="post" action="<?= e(url('/admin/payments/' . $pid . '/reject')) ?>" data-once>
                    <?= csrf_field() ?>
                    <fieldset class="field">
                        <legend class="label">Reason for rejection <span class="req">*</span></legend>
                        <label class="check">
                            <input type="radio" name="reason_choice" value="unclear" checked>
                            <span>Screenshot unclear or unreadable</span>
                        </label>
                        <label class="check">
                            <input type="radio" name="reason_choice" value="mismatch">
                            <span>Amount does not match</span>
                        </label>
                        <label class="check">
                            <input type="radio" name="reason_choice" value="other">
                            <span>Other reason (specify below)</span>
                        </label>
                    </fieldset>
                    <div class="field mt-1">
                        <label for="reason-custom-<?= $pid ?>">Additional note for client (optional)</label>
                        <input type="text" id="reason-custom-<?= $pid ?>" name="reason_custom" maxlength="200" placeholder="e.g. Reference number not found in GCash">
                    </div>
                    <div class="sheet-actions">
                        <button type="submit" class="btn btn-danger btn-block">Reject payment</button>
                        <a class="btn btn-block" href="#payment-<?= $pid ?>" data-sheet-close>Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
