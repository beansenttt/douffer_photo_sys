<?php
declare(strict_types=1);

/**
 * Screen A10: Project Tracker.
 * Stage chips with counts, overdue cards, editing checklist, Move to Ready bottom sheet.
 */
?>
<p class="eyebrow">Screen A10 · Project Tracker</p>

<!-- Stage Filter Chips with Counts -->
<div class="chips mb-1" role="navigation" aria-label="Project stages">
    <a class="chip<?= ($filter === 'all' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects')) ?>"<?= ($filter === 'all' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        All <span class="chip-count">(<?= (int) $stageCounts['all'] ?>)</span>
    </a>
    <a class="chip<?= ($filter === 'booked' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?stage=booked')) ?>"<?= ($filter === 'booked' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        Booked <span class="chip-count">(<?= (int) $stageCounts['booked'] ?>)</span>
    </a>
    <a class="chip<?= ($filter === 'covered' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?stage=covered')) ?>"<?= ($filter === 'covered' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        Covered <span class="chip-count">(<?= (int) $stageCounts['covered'] ?>)</span>
    </a>
    <a class="chip<?= ($filter === 'editing' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?stage=editing')) ?>"<?= ($filter === 'editing' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        Editing <span class="chip-count">(<?= (int) $stageCounts['editing'] ?>)</span>
    </a>
    <a class="chip<?= ($filter === 'ready' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?stage=ready')) ?>"<?= ($filter === 'ready' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        Ready <span class="chip-count">(<?= (int) $stageCounts['ready'] ?>)</span>
    </a>
    <a class="chip<?= ($filter === 'released' && empty($isOverdueOnly)) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?stage=released')) ?>"<?= ($filter === 'released' && empty($isOverdueOnly)) ? ' aria-current="true"' : '' ?>>
        Released <span class="chip-count">(<?= (int) $stageCounts['released'] ?>)</span>
    </a>
    <?php if ($stageCounts['overdue'] > 0): ?>
        <a class="chip<?= !empty($isOverdueOnly) ? ' is-active' : '' ?>" href="<?= e(url('/admin/projects?overdue=1')) ?>"<?= !empty($isOverdueOnly) ? ' aria-current="true"' : '' ?>>
            <?= partial('partials/tag', ['class' => 'overdue', 'label' => 'Overdue']) ?>
            <span class="chip-count">(<?= (int) $stageCounts['overdue'] ?>)</span>
        </a>
    <?php endif; ?>
</div>

<!-- Search Bar -->
<form method="get" action="<?= e(url('/admin/projects')) ?>" class="search-row mb-1">
    <?php if ($filter !== 'all'): ?>
        <input type="hidden" name="stage" value="<?= e($filter) ?>">
    <?php endif; ?>
    <?php if (!empty($isOverdueOnly)): ?>
        <input type="hidden" name="overdue" value="1">
    <?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search client, ref no., venue...">
    <button type="submit" class="btn btn-outline"><?= icon('search') ?> Search</button>
</form>

<!-- Project Cards List -->
<?php if (empty($projects)): ?>
    <div class="empty">
        <p class="mb-0"><strong>No projects found.</strong></p>
        <p class="small muted mb-0">No deliverable projects match the active filter or search query.</p>
    </div>
<?php else: ?>
    <div class="stack">
        <?php foreach ($projects as $p):
            $pid = (int) $p['id'];
            $bid = (int) $p['booking_id'];
            $isOverdue = !empty($p['is_overdue']);
            $stage = (string) $p['stage'];

            $stageTagClass = match ($stage) {
                'ready'    => 'ok',
                'editing'  => 'pending',
                'released' => 'released',
                default    => 'info',
            };
        ?>
            <article class="card<?= $isOverdue ? ' reject-box card-overdue' : '' ?>" id="project-card-<?= $pid ?>">
                <div class="card-row">
                    <div>
                        <h2 class="card-title"><?= e($p['client_name']) ?> – <?= e($p['event_type']) ?></h2>
                        <div class="card-meta">
                            <?= e(date_long($p['event_date'])) ?> · <?= e($p['package_name']) ?>
                        </div>
                        <div class="card-meta">
                            Ref. <strong><?= e($p['reference_code']) ?></strong> · Venue: <?= e($p['venue']) ?>
                        </div>
                    </div>
                    <div class="stack-right">
                        <?= partial('partials/tag', ['class' => $stageTagClass, 'label' => ucfirst($stage)]) ?>
                        <?php if ($isOverdue): ?>
                            <?= partial('partials/tag', ['class' => 'overdue', 'label' => 'Overdue']) ?>
                        <?php endif; ?>
                        <?php if (!empty($p['has_unpaid_balance'])): ?>
                            <?= partial('partials/tag', ['class' => 'balance', 'label' => peso($p['balance_amount']) . ' due']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Due date and countdown -->
                <div class="card-row mt-1">
                    <span class="muted">Target due date</span>
                    <span>
                        <strong><?= e(date_short($p['due_date'])) ?></strong>
                        <?php if ($stage === 'released'): ?>
                            <span class="muted"> · Completed</span>
                        <?php elseif ($isOverdue): ?>
                            <strong class="req"> · Overdue by <?= (int) $p['days_overdue'] ?> day<?= (int) $p['days_overdue'] === 1 ? '' : 's' ?></strong>
                        <?php else: ?>
                            <span class="muted"> · <?= (int) $p['days_left'] ?> day<?= (int) $p['days_left'] === 1 ? '' : 's' ?> left</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Stage-specific interactive controls -->
                <?php if ($stage === 'booked'): ?>
                    <form method="post" action="<?= e(url('/admin/projects/' . $pid . '/stage')) ?>" data-once class="mt-1">
                        <?= csrf_field() ?>
                        <button type="submit" name="stage" value="covered" class="btn btn-primary btn-block">
                            <?= icon('check') ?> Mark as Covered
                        </button>
                    </form>
                <?php elseif ($stage === 'covered'): ?>
                    <form method="post" action="<?= e(url('/admin/projects/' . $pid . '/stage')) ?>" data-once class="mt-1">
                        <?= csrf_field() ?>
                        <button type="submit" name="stage" value="editing" class="btn btn-primary btn-block">
                            <?= icon('check') ?> Move to Editing
                        </button>
                    </form>
                <?php elseif ($stage === 'editing'): ?>
                    <!-- Editing checklist form -->
                    <form method="post" action="<?= e(url('/admin/projects/' . $pid . '/checklist')) ?>" data-once class="mt-1">
                        <?= csrf_field() ?>
                        <div class="stack">
                            <label class="check">
                                <input type="checkbox" name="editing_done" value="1"<?= !empty($p['editing_done']) ? ' checked' : '' ?>>
                                <span>Editing &amp; culling done</span>
                            </label>
                            <label class="check">
                                <input type="checkbox" name="rendering_done" value="1"<?= !empty($p['rendering_done']) ? ' checked' : '' ?>>
                                <span>Color grading &amp; rendering done</span>
                            </label>
                            <label class="check">
                                <input type="checkbox" name="album_done" value="1"<?= !empty($p['album_done']) ? ' checked' : '' ?>>
                                <span>Album / layout done</span>
                            </label>
                        </div>
                        <div class="row-between mt-1">
                            <button type="submit" class="btn btn-outline btn-small"><?= icon('check') ?> Save checklist</button>
                            <a class="btn btn-primary btn-small" href="#ready-sheet-<?= $pid ?>" data-sheet-open="ready-sheet-<?= $pid ?>">Move to Ready (⋯)</a>
                        </div>
                    </form>

                    <!-- Move to Ready bottom sheet confirmation -->
                    <div class="sheet" id="ready-sheet-<?= $pid ?>" role="dialog" aria-modal="true" aria-labelledby="ready-title-<?= $pid ?>">
                        <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
                        <div class="sheet-panel">
                            <h3 class="h3 mt-0" id="ready-title-<?= $pid ?>">Move to Ready?</h3>
                            <p class="card-meta">
                                Mark deliverables for <strong><?= e($p['client_name']) ?></strong> (<?= e($p['reference_code']) ?>) as ready for handover?
                                This will also notify the client in their in-app chat thread.
                            </p>
                            <form method="post" action="<?= e(url('/admin/projects/' . $pid . '/stage')) ?>" data-once>
                                <?= csrf_field() ?>
                                <input type="hidden" name="stage" value="ready">
                                <div class="sheet-actions">
                                    <button type="submit" class="btn btn-primary btn-block"><?= icon('check') ?> Confirm: Move to Ready</button>
                                    <a class="btn btn-block" href="#" data-sheet-close>Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php elseif ($stage === 'ready'): ?>
                    <div class="mt-1">
                        <a class="btn btn-primary btn-block" href="<?= e(url('/admin/projects/' . $pid . '/files')) ?>">
                            <?= icon('folder') ?> Open Files &amp; Handover (A11)
                        </a>
                    </div>
                <?php elseif ($stage === 'released'): ?>
                    <div class="card-meta mt-1">
                        Released on <strong><?= e(date_short($p['released_at'] ?? '')) ?></strong> via <?= e(ucfirst(str_replace('_', ' ', $p['released_how'] ?? 'pickup'))) ?> to <strong><?= e($p['received_by'] ?? $p['client_name']) ?></strong>
                    </div>
                    <div class="mt-1">
                        <a class="btn btn-outline btn-block btn-small" href="<?= e(url('/admin/projects/' . $pid . '/files')) ?>">
                            View Files &amp; Handover Record
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Bottom navigation links on card -->
                <div class="mt-1 small">
                    <a class="link-like" href="<?= e(url('/admin/projects/' . $pid . '/files')) ?>">Files and handover details →</a>
                    <span class="muted">·</span>
                    <a class="link-like" href="<?= e(url('/admin/bookings/' . $bid)) ?>">View booking (A6) →</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
