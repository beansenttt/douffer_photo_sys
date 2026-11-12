<?php
/* Screen A18: Crew - My Events (view-only, Scenario T7).
   Vars: $user $mustChange $crewMember $nextEvent $laterEvents $pastEvents */
?>
<?php if ($mustChange): ?>
    <div class="alert alert-warning" role="alert">
        <?= icon('alert') ?>
        <div>
            <strong>Change the default password.</strong>
            <a class="btn btn-small" href="<?= e(url('/account/password')) ?>">Change password</a>
        </div>
    </div>
<?php endif; ?>

<div class="row-between section">
    <div>
        <p class="eyebrow">Hello, <?= e($user['name']) ?></p>
        <?php if ($crewMember): ?>
            <p class="small muted">
                Role: <strong><?= e(ucfirst((string) $crewMember['type'])) ?></strong>
                <?php if (!empty($crewMember['phone'])): ?>
                    · <?= e(format_mobile($crewMember['phone'])) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-small btn-outline" href="<?= e(url('/account/password')) ?>"><?= icon('key') ?> Password</a>
</div>

<?php if (!$nextEvent && empty($laterEvents) && empty($pastEvents)): ?>
    <div class="empty">
        <?= icon('calendar', 'icon icon-xl') ?>
        <p><strong>No events assigned yet.</strong></p>
        <p class="muted">When the studio assigns you to an upcoming event, the date, call time, venue and coverage details will appear here.</p>
    </div>
<?php endif; ?>

<?php if ($nextEvent): ?>
    <section class="card stack section" aria-labelledby="next-ev-title">
        <div class="row-between">
            <span class="eyebrow">Next assigned event</span>
            <?= partial('partials/tag', ['status' => $nextEvent['status']]) ?>
        </div>

        <h2 class="card-title" id="next-ev-title">
            <?= e($nextEvent['client_name']) ?> – <?= e($nextEvent['event_type']) ?>
        </h2>
        <p class="card-meta">
            <strong><?= e(date_long($nextEvent['event_date'])) ?></strong>
            <?php if (!empty($nextEvent['start_time'])): ?>
                · <?= e(time12($nextEvent['start_time'])) ?><?= !empty($nextEvent['end_time']) ? ' – ' . e(time12($nextEvent['end_time'])) : '' ?>
            <?php endif; ?>
        </p>

        <div class="stack-s">
            <div class="card-row">
                <span>Your assignment</span>
                <strong><?= e(ucfirst((string) $nextEvent['crew_role'])) ?></strong>
            </div>
            <div class="card-row">
                <span>Call time</span>
                <strong>
                    <?php if (!empty($nextEvent['effective_call_time'])): ?>
                        <?= e(time12($nextEvent['effective_call_time'])) ?>
                        <?php if (empty($nextEvent['call_time'])): ?>
                            <span class="small muted">(1 hr before start)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        TBA
                    <?php endif; ?>
                </strong>
            </div>
            <div class="card-row">
                <span>Venue</span>
                <strong><?= e($nextEvent['venue']) ?></strong>
            </div>
            <div class="card-row">
                <span>Client contact</span>
                <strong>
                    <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $nextEvent['client_mobile'])) ?>">
                        <?= e(format_mobile($nextEvent['client_mobile'])) ?>
                    </a>
                </strong>
            </div>
            <div class="card-row">
                <span>Package</span>
                <strong><?= e($nextEvent['package_name']) ?></strong>
            </div>
            <div class="card-row">
                <span>Reference no.</span>
                <span><?= e($nextEvent['reference_code']) ?></span>
            </div>
        </div>

        <?php if (!empty($nextEvent['inclusions_list']) || !empty($nextEvent['addon_names'])): ?>
            <div class="section">
                <h3 class="h4">Coverage inclusions</h3>
                <ul class="bullet-list">
                    <?php foreach ($nextEvent['inclusions_list'] as $inc): ?>
                        <li><?= e($inc) ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($nextEvent['addon_names'] as $addonName): ?>
                        <li><strong>Add-on:</strong> <?= e($addonName) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($nextEvent['special_requests'])): ?>
            <div class="section">
                <h3 class="h4">Client special requests</h3>
                <p class="small"><?= nl2br(e($nextEvent['special_requests'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($nextEvent['notes'])): ?>
            <div class="section">
                <h3 class="h4">Owner &amp; studio notes</h3>
                <div class="stack-s">
                    <?php foreach ($nextEvent['notes'] as $note): ?>
                        <div class="alert alert-info">
                            <div>
                                <p><?= nl2br(e($note['body'])) ?></p>
                                <p class="small muted">
                                    <?= e($note['author_name'] ?: 'Studio') ?> · <?= e(datetime_short($note['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($nextEvent['team'])): ?>
            <div class="section">
                <h3 class="h4">Assigned crew team</h3>
                <ul class="bullet-list">
                    <?php foreach ($nextEvent['team'] as $tm): ?>
                        <li>
                            <strong><?= e($tm['name']) ?></strong>
                            (<?= e(ucfirst((string) $tm['role'])) ?> · <?= e(ucfirst((string) $tm['type'])) ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($nextEvent['equipment'])): ?>
            <div class="section">
                <h3 class="h4">Equipment checklist</h3>
                <ul class="bullet-list">
                    <?php foreach ($nextEvent['equipment'] as $eq): ?>
                        <li>
                            <?= (int) $eq['is_checked'] === 1 ? '✓' : '○' ?>
                            <?= e($eq['name']) ?>
                            <?php if (!empty($eq['category'])): ?>
                                <span class="small muted">(<?= e($eq['category']) ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!empty($laterEvents)): ?>
    <section class="section" aria-labelledby="later-ev-heading">
        <h2 class="h3" id="later-ev-heading">Later events (<?= count($laterEvents) ?>)</h2>
        <div class="stack">
            <?php foreach ($laterEvents as $ev): ?>
                <article class="card stack-s">
                    <div class="row-between">
                        <strong><?= e(date_long($ev['event_date'])) ?></strong>
                        <?= partial('partials/tag', ['status' => $ev['status']]) ?>
                    </div>
                    <h3 class="card-title"><?= e($ev['client_name']) ?> – <?= e($ev['event_type']) ?></h3>
                    <p class="card-meta">
                        <?php if (!empty($ev['start_time'])): ?>
                            <?= e(time12($ev['start_time'])) ?><?= !empty($ev['end_time']) ? ' – ' . e(time12($ev['end_time'])) : '' ?> ·
                        <?php endif; ?>
                        Call time: <strong><?= !empty($ev['effective_call_time']) ? e(time12($ev['effective_call_time'])) : 'TBA' ?></strong>
                        · Role: <strong><?= e(ucfirst((string) $ev['crew_role'])) ?></strong>
                    </p>
                    <p class="small"><strong>Venue:</strong> <?= e($ev['venue']) ?></p>
                    <p class="small"><strong>Package:</strong> <?= e($ev['package_name']) ?> · <strong>Contact:</strong> <?= e(format_mobile($ev['client_mobile'])) ?></p>
                    <?php if (!empty($ev['notes'])): ?>
                        <?php foreach ($ev['notes'] as $note): ?>
                            <p class="small muted"><strong>Note:</strong> <?= e($note['body']) ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($pastEvents)): ?>
    <details class="card section">
        <summary>Past / completed events (<?= count($pastEvents) ?>)</summary>
        <div class="stack section">
            <?php foreach ($pastEvents as $ev): ?>
                <div class="card-row">
                    <div>
                        <strong><?= e($ev['client_name']) ?> – <?= e($ev['event_type']) ?></strong>
                        <p class="small muted"><?= e(date_short($ev['event_date'])) ?> · <?= e($ev['venue']) ?></p>
                    </div>
                    <?= partial('partials/tag', ['status' => $ev['status']]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>

<p class="note"><?= icon('shield') ?> View-only. Payments and other records are hidden.</p>
