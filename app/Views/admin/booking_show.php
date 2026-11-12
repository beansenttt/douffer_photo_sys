<?php
/*
 * Screen A6: Booking Details (hub).
 * Header card + Tabs: Overview · Payments · Crew · Project · Files.
 * In Phase 3, Payments and Overview are fully active; Crew, Project and Files link to their Phase 4 screens.
 */
use App\Core\Auth;

$bid = (int) $booking['id'];
$status = (string) $booking['status'];
$isArchived = (int) ($booking['is_archived'] ?? 0) === 1;
$tagClass = $isArchived ? 'archived' : ($status === 'confirmed' ? 'confirmed' : ($status === 'completed' ? 'completed' : ($status === 'cancelled' ? 'cancelled' : 'pending')));
$tagLabel = $isArchived ? 'Archived' : ucfirst($status);

$notesCount = count($notes ?? []);
$tabs = [
    ['overview', 'Overview', '/admin/bookings/' . $bid . '?tab=overview'],
    ['payments', 'Payments', '/admin/bookings/' . $bid . '?tab=payments'],
    ['crew', 'Crew', '/admin/bookings/' . $bid . '?tab=crew'],
    ['project', 'Project', '/admin/bookings/' . $bid . '?tab=project'],
    ['files', 'Files', '/admin/bookings/' . $bid . '?tab=files'],
    ['notes', 'Notes' . ($notesCount > 0 ? " ($notesCount)" : ''), '/admin/bookings/' . $bid . '?tab=notes'],
];
?>
<p class="eyebrow">Screen A6 · Booking Hub</p>

<article class="card">
    <div class="card-row">
        <div>
            <h2 class="card-title"><?= e($booking['full_name']) ?> – <?= e($booking['event_type']) ?></h2>
            <div class="card-meta">
                <?= e(date_long($booking['event_date'])) ?>
                <?php if (!empty($booking['start_time'])): ?>
                    · <?= e(time12($booking['start_time'])) ?><?= !empty($booking['end_time']) ? ' – ' . e(time12($booking['end_time'])) : '' ?>
                <?php endif; ?>
            </div>
            <div class="card-meta">Venue: <?= e($booking['venue']) ?> · Ref. <strong><?= e($booking['reference_code']) ?></strong></div>
        </div>
        <div class="stack-right">
            <?= partial('partials/tag', ['class' => $tagClass, 'label' => $tagLabel]) ?>
            <?php if ($status === 'confirmed' && $balance > 0): ?>
                <?= partial('partials/tag', ['class' => 'balance', 'label' => 'Balance due']) ?>
            <?php endif; ?>
        </div>
    </div>
</article>

<nav class="tabs mt-1" aria-label="Booking sections">
    <?php foreach ($tabs as [$key, $label, $href]): ?>
        <a href="<?= e(url($href)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'payments'): ?>
    <section class="card" aria-label="Payment summary">
        <div class="card-row">
            <span class="muted">Total</span>
            <strong class="money"><?= e(peso($booking['total_amount'])) ?></strong>
        </div>
        <div class="card-row">
            <div>
                <span class="muted">Down payment <?= $downPayment && $downPayment['status'] === 'verified' ? '(paid)' : '(' . e($downPayment['status'] ?? 'required') . ')' ?></span>
                <?php if ($downPayment): ?>
                    <div class="small muted">
                        GCash ref. no.: <strong><?= e($downPayment['gcash_reference_no'] ?: '—') ?></strong>
                        · Date: <?= e(date_short(substr((string) ($downPayment['paid_at'] ?? $downPayment['created_at']), 0, 10))) ?>
                    </div>
                <?php endif; ?>
            </div>
            <strong class="money"><?= e(peso($downPayment ? $downPayment['amount'] : $booking['down_payment_due'])) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Total verified paid</span>
            <strong class="money"><?= e(peso($paid)) ?></strong>
        </div>
        <div class="card-row">
            <span class="strong">Balance due</span>
            <strong class="money price"><?= e(peso($balance)) ?></strong>
        </div>

        <?php if ($status === 'pending' && $downPayment && $downPayment['status'] === 'pending' && Auth::can('verify_payments')): ?>
            <div class="alert alert-warning mt-1">
                <?= icon('alert') ?>
                <div>
                    <strong>Down payment waiting for verification.</strong>
                    <p class="mb-0"><a class="link-like" href="<?= e(url('/admin/payments#payment-' . (int) $downPayment['id'])) ?>">Open in Payments to Verify (A7)</a></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (Auth::can('record_payments') && in_array($status, ['confirmed', 'completed'], true) && $balance > 0): ?>
            <div class="mt-1">
                <a class="btn btn-primary btn-block" href="#record-payment" data-sheet-open="record-payment">
                    <?= icon('wallet') ?> Record balance payment
                </a>
            </div>
        <?php endif; ?>

        <div class="btn-row mt-1">
            <a class="btn btn-outline" href="<?= e(url('/admin/bookings/' . $bid . '/confirmation')) ?>"><?= icon('print') ?> Print receipt</a>
            <a class="btn btn-outline" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates')) ?>"><?= icon('chat') ?> Message client</a>
        </div>
    </section>

    <section class="card mt-1" aria-label="Payment history">
        <h3 class="card-title">Recorded payments (<?= count($payments) ?>)</h3>
        <?php if (empty($payments)): ?>
            <p class="muted mb-0">No payments recorded yet.</p>
        <?php else: ?>
            <?php foreach ($payments as $p):
                $pStatus = (string) $p['status'];
                $pClass = $pStatus === 'verified' ? 'confirmed' : ($pStatus === 'rejected' ? 'rejected' : 'pending');
            ?>
                <div class="card-row">
                    <div>
                        <strong><?= e(ucfirst($p['type'])) ?> · <?= e(strtoupper($p['method'])) ?></strong>
                        <?= partial('partials/tag', ['class' => $pClass, 'label' => ucfirst($pStatus)]) ?>
                        <?php if (!empty($p['is_forfeited'])): ?>
                            <?= partial('partials/tag', ['class' => 'overdue', 'label' => 'Forfeited']) ?>
                        <?php endif; ?>
                        <div class="small muted">
                            <?= e(datetime_short($p['paid_at'] ?? $p['created_at'])) ?>
                            <?php if (!empty($p['gcash_reference_no'])): ?> · Ref. <?= e($p['gcash_reference_no']) ?><?php endif; ?>
                            <?php if (!empty($p['receipt_no'])): ?>
                                · <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid . '/confirmation?tab=receipt&payment_id=' . (int) $p['id'])) ?>"><?= e($p['receipt_no']) ?></a>
                            <?php endif; ?>
                        </div>
                        <?php if ($pStatus === 'rejected' && !empty($p['rejection_reason'])): ?>
                            <div class="small field-error">Reason: <?= e($p['rejection_reason']) ?></div>
                        <?php endif; ?>
                    </div>
                    <strong class="money"><?= e(peso($p['amount'])) ?></strong>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'overview'): ?>
    <section class="card" aria-label="Booking overview">
        <div class="card-row">
            <span class="muted">Client</span>
            <strong><?= e($booking['full_name']) ?></strong>
        </div>
        <div class="card-row">
            <span class="muted">Mobile</span>
            <span><?= e($booking['mobile']) ?></span>
        </div>
        <?php if (!empty($booking['messenger_name'])): ?>
            <div class="card-row">
                <span class="muted">Messenger</span>
                <span><?= e($booking['messenger_name']) ?></span>
            </div>
        <?php endif; ?>
        <div class="card-row">
            <span class="muted">Source channel</span>
            <span><?= e(ucfirst(str_replace('_', '-', (string) $booking['source_channel']))) ?></span>
        </div>
        <div class="card-row">
            <span class="muted">Package</span>
            <strong><?= e($booking['package_name']) ?> (<?= e(peso($booking['package_price'])) ?>)</strong>
        </div>
        <?php if (!empty($addons)): ?>
            <div class="card-row">
                <span class="muted">Add-ons</span>
                <span>
                    <?php foreach ($addons as $idx => $a): ?>
                        <?= $idx > 0 ? ', ' : '' ?><?= e($a['name']) ?> (<?= e(peso($a['price_at_booking'])) ?>)
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endif; ?>
        <?php if (!empty($booking['special_requests'])): ?>
            <div class="card-row">
                <span class="muted">Special requests</span>
                <span><?= e($booking['special_requests']) ?></span>
            </div>
        <?php endif; ?>
    </section>

    <section class="card mt-1" aria-label="History timeline">
        <h3 class="card-title">History timeline</h3>
        <ul class="timeline mt-1">
            <?php foreach ($changes as $ch): ?>
                <li>
                    <strong><?= e(ucfirst($ch['change_type'])) ?></strong>
                    <?php if (!empty($ch['new_value'])): ?> · <?= e($ch['new_value']) ?><?php endif; ?>
                    <?php if (!empty($ch['reason'])): ?> — <?= e($ch['reason']) ?><?php endif; ?>
                    <div class="small muted">
                        <?= e(datetime_short($ch['changed_at'])) ?>
                        <?= !empty($ch['user_name']) ? ' · by ' . e($ch['user_name']) : '' ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

<?php elseif ($tab === 'crew'):
    $bookingEquipment = \App\Services\CrewService::getEquipmentForBooking((int) $booking['id']);
?>
    <section class="card" aria-label="Assigned crew">
        <div class="row-between">
            <h3 class="card-title mb-0">Assigned crew (<?= count($crew) ?>)</h3>
            <a class="btn btn-outline btn-small" href="<?= e(url('/admin/crew?date=' . $booking['event_date'])) ?>">Manage on Screen A9</a>
        </div>

        <?php if (empty($crew)): ?>
            <div class="alert alert-warning mt-1">
                <?= icon('alert') ?>
                <div>
                    <strong>No crew assigned yet.</strong>
                    <p class="mb-0">This event has no crew allocated. Visit Screen A9 to assign photographers.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="stack mt-1">
                <?php foreach ($crew as $c): ?>
                    <div class="card-row">
                        <div>
                            <strong><?= e($c['name']) ?></strong> (<?= e(ucfirst($c['type'] ?? 'crew')) ?>)
                            <?= partial('partials/tag', ['class' => ($c['role'] ?? '') === 'lead' ? 'ok' : 'info', 'label' => ucfirst($c['role'] ?? 'lead')]) ?>
                            <div class="small muted">
                                Call time: <strong><?= !empty($c['call_time']) ? e(time12($c['call_time'])) : 'Default (1h prior)' ?></strong>
                                <?php if (!empty($c['phone'])): ?> · Tel: <?= e(format_mobile($c['phone'])) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Equipment Checklist Section in Booking Show -->
    <section class="card mt-1" aria-label="Equipment checklist">
        <h3 class="card-title">Equipment Checklist</h3>
        <?php if (empty($bookingEquipment)): ?>
            <p class="muted mb-0">No active equipment items found. Checklist can be prepared in Screen A9.</p>
        <?php else: ?>
            <div class="stack mt-1">
                <?php foreach ($bookingEquipment as $eq):
                    $checked = !empty($eq['is_checked']);
                ?>
                    <div class="card-row">
                        <span>
                            <?= $checked ? '✓' : '—' ?>
                            <strong><?= e($eq['name']) ?></strong>
                            <span class="muted small">(<?= e($eq['category'] ?? 'General') ?>)</span>
                        </span>
                        <span>
                            <?= partial('partials/tag', ['class' => $checked ? 'ok' : '', 'label' => $checked ? 'Prepared' : 'Unchecked']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="mt-1">
            <a class="btn btn-primary btn-block" href="<?= e(url('/admin/crew?date=' . $booking['event_date'])) ?>">Manage crew &amp; equipment (A9)</a>
        </div>
    </section>

<?php elseif ($tab === 'project'):
    $isProjectOverdue = $project ? ($project['due_date'] < \App\Core\Clock::today() && $project['stage'] !== 'released') : false;
?>
    <section class="card">
        <div class="row-between">
            <h3 class="card-title mb-0">Project deliverable</h3>
            <?php if ($project): ?>
                <a class="btn btn-outline btn-small" href="<?= e(url('/admin/projects')) ?>">Open A10</a>
            <?php endif; ?>
        </div>
        <?php if (!$project): ?>
            <p class="muted mb-0 mt-1">A project tracker item is created automatically once the booking is confirmed.</p>
        <?php else: ?>
            <div class="card-row mt-1">
                <span class="muted">Current stage</span>
                <span>
                    <?= partial('partials/tag', ['class' => $project['stage'] === 'released' ? 'released' : ($project['stage'] === 'ready' ? 'ok' : 'info'), 'label' => ucfirst($project['stage'])]) ?>
                    <?php if ($isProjectOverdue): ?>
                        <?= partial('partials/tag', ['class' => 'overdue', 'label' => 'Overdue']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-row">
                <span class="muted">Due date</span>
                <strong><?= e(date_short($project['due_date'])) ?><?= $isProjectOverdue ? ' (Overdue)' : '' ?></strong>
            </div>
            <div class="card-row">
                <span class="muted">Editing checklist</span>
                <span>
                    Editing: <?= !empty($project['editing_done']) ? '✓' : '—' ?> ·
                    Rendering: <?= !empty($project['rendering_done']) ? '✓' : '—' ?> ·
                    Album: <?= !empty($project['album_done']) ? '✓' : '—' ?>
                </span>
            </div>
            <div class="mt-1">
                <a class="btn btn-primary btn-block" href="<?= e(url('/admin/projects')) ?>">Open Project Tracker (A10)</a>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'files'): ?>
    <section class="card">
        <div class="row-between">
            <h3 class="card-title mb-0">Files &amp; handover</h3>
            <?php if ($project): ?>
                <a class="btn btn-outline btn-small" href="<?= e(url('/admin/projects/' . (int) $project['id'] . '/files')) ?>">Open A11</a>
            <?php endif; ?>
        </div>
        <?php if (!$project): ?>
            <p class="muted mb-0 mt-1">Available after booking confirmation.</p>
        <?php else: ?>
            <div class="card-row mt-1">
                <span class="muted">Raw files folder</span>
                <span class="small"><?= e($project['raw_folder'] ?: 'Not recorded yet') ?></span>
            </div>
            <div class="card-row">
                <span class="muted">Edited files folder</span>
                <span class="small"><?= e($project['edited_folder'] ?: 'Not recorded yet') ?></span>
            </div>
            <div class="card-row">
                <span class="muted">Backups</span>
                <span>
                    Local: <?= !empty($project['local_backup_done']) ? '✓' : '—' ?> ·
                    Cloud: <?= !empty($project['cloud_copy_done']) ? '✓' : '—' ?>
                </span>
            </div>
            <?php if ($project['stage'] === 'released'): ?>
                <div class="card-row">
                    <span class="muted">Handover</span>
                    <span>Released on <?= e(date_short($project['released_at'] ?? '')) ?> via <?= e(ucfirst(str_replace('_', ' ', $project['released_how'] ?? 'pickup'))) ?></span>
                </div>
                <div class="card-row">
                    <span class="muted">Received by</span>
                    <span><?= e($project['received_by'] ?? 'Client') ?></span>
                </div>
            <?php endif; ?>
            <div class="mt-1">
                <a class="btn btn-primary btn-block" href="<?= e(url('/admin/projects/' . (int) $project['id'] . '/files')) ?>">Open Files &amp; Handover (A11)</a>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($tab === 'notes'): ?>
    <section class="card" aria-label="Add internal note">
        <h3 class="card-title">Add internal note</h3>
        <p class="card-meta">Notes are internal to staff and optionally assigned crew. They are never visible to the client.</p>
        <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/notes')) ?>" data-once>
            <?= csrf_field() ?>
            <div class="field">
                <label for="note-body" class="sr-only">Note body</label>
                <textarea id="note-body" name="body" rows="3" required placeholder="Write internal instructions, client preferences, or operational details..."></textarea>
            </div>
            <div class="field mt-1">
                <label class="check">
                    <input type="checkbox" name="visible_to_crew" value="1" checked>
                    <span>Visible to assigned crew in Screen A18</span>
                </label>
            </div>
            <div class="mt-1">
                <button type="submit" class="btn btn-primary"><?= icon('plus') ?> Save note</button>
            </div>
        </form>
    </section>

    <section class="card mt-1" aria-label="Internal notes list">
        <h3 class="card-title">Internal notes (<?= count($notes) ?>)</h3>
        <?php if (empty($notes)): ?>
            <p class="muted mb-0">No internal notes added yet.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($notes as $n): ?>
                    <article class="card-row">
                        <div>
                            <p class="mb-0"><?= nl2br(e($n['body'])) ?></p>
                            <div class="small muted mt-1">
                                <?= e(datetime_short($n['created_at'])) ?>
                                <?= !empty($n['author_name']) ? ' · by ' . e($n['author_name']) : '' ?>
                                · <?= (int) ($n['visible_to_crew'] ?? 0) === 1
                                    ? partial('partials/tag', ['class' => 'ok', 'label' => 'Crew visible'])
                                    : partial('partials/tag', ['class' => '', 'label' => 'Staff only']) ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<footer class="card mt-1">
    <div class="hub-links">
        <?php if (Auth::can('manage_messages')): ?>
            <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid . '/chat')) ?>">In-App Chat (A19)</a>
            <span class="muted">·</span>
        <?php endif; ?>
        <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid . '/messages/templates')) ?>">Client Messages (A12)</a>
        <span class="muted">·</span>
        <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
            <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid . '/change')) ?>">Reschedule</a>
            <span class="muted">·</span>
            <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid . '/change?mode=cancel')) ?>">Cancel</a>
        <?php elseif ($status === 'completed'): ?>
            <span class="muted">Booking completed</span>
        <?php else: ?>
            <span class="muted">Booking cancelled</span>
        <?php endif; ?>
        <?php if (Auth::can('archive_records')): ?>
            <span class="muted">·</span>
            <a class="link-like" href="#archive-sheet" data-sheet-open="archive-sheet"><?= $isArchived ? 'Restore' : 'Archive' ?> (⋯)</a>
        <?php endif; ?>
    </div>
</footer>

<?php if (Auth::can('record_payments') && in_array($status, ['confirmed', 'completed'], true) && $balance > 0): ?>
    <div class="sheet" id="record-payment" role="dialog" aria-modal="true" aria-labelledby="record-payment-title">
        <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
        <div class="sheet-panel">
            <h3 class="h3 mt-0" id="record-payment-title">Record balance payment</h3>
            <p class="card-meta"><?= e($booking['full_name']) ?> · Remaining balance: <strong><?= e(peso($balance)) ?></strong></p>
            <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/payments')) ?>" data-once>
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="balance">
                <div class="field">
                    <label for="pay-amount">Amount received (₱) <span class="req">*</span></label>
                    <input type="number" id="pay-amount" name="amount" step="0.01" min="1" max="<?= e((string) $balance) ?>" value="<?= e(old('amount', number_format($balance, 2, '.', ''))) ?>" required>
                </div>
                <div class="field mt-1">
                    <label for="pay-method">Payment method <span class="req">*</span></label>
                    <select id="pay-method" name="method">
                        <option value="cash"<?= old('method', 'cash') === 'cash' ? ' selected' : '' ?>>Cash</option>
                        <option value="gcash"<?= old('method') === 'gcash' ? ' selected' : '' ?>>GCash</option>
                        <option value="other"<?= old('method') === 'other' ? ' selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="field mt-1">
                    <label for="pay-ref">GCash reference number (if paid by GCash)</label>
                    <input type="text" id="pay-ref" name="gcash_reference_no" value="<?= e(old('gcash_reference_no')) ?>" inputmode="numeric" placeholder="e.g. 1001234567890">
                </div>
                <div class="field mt-1">
                    <label for="pay-notes">Notes (optional)</label>
                    <input type="text" id="pay-notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="200" placeholder="e.g. Paid in person at reception">
                </div>
                <div class="sheet-actions">
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('check') ?> Save &amp; issue receipt</button>
                    <a class="btn btn-block" href="#" data-sheet-close>Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (Auth::can('archive_records')): ?>
    <div class="sheet" id="archive-sheet" role="dialog" aria-modal="true" aria-labelledby="archive-title">
        <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
        <div class="sheet-panel">
            <h3 class="h3 mt-0" id="archive-title"><?= $isArchived ? 'Restore this booking?' : 'Archive this booking?' ?></h3>
            <p class="card-meta">
                <?= $isArchived
                    ? 'This booking will return to the active Bookings list and calendar.'
                    : 'Archive this booking? You can restore it later from the Archived filter (no records are ever permanently deleted).' ?>
            </p>
            <form method="post" action="<?= e(url('/admin/bookings/' . $bid . '/archive')) ?>" data-once>
                <?= csrf_field() ?>
                <div class="sheet-actions">
                    <button type="submit" class="btn <?= $isArchived ? 'btn-primary' : 'btn-danger' ?> btn-block">
                        <?= $isArchived ? 'Restore booking' : 'Archive' ?>
                    </button>
                    <a class="btn btn-block" href="#" data-sheet-close>Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
