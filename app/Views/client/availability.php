<?php
/* Screen C2: Check Availability. Server-rendered month grid (links, no JS needed).
   Vars: $year $mon $month $prevMonth $nextMonth $map $today $selected $reason */
$first = sprintf('%04d-%02d-01', $year, $mon);
$offset = (int) date('w', strtotime($first)); // 0 = Sunday
$lastDay = (int) date('t', strtotime($first));
$label = date('F Y', strtotime($first));
?>
<p class="muted">Choose your event date</p>

<section class="cal" aria-label="Calendar for <?= e($label) ?>">
    <div class="cal-head">
        <?php if ($prevMonth): ?>
            <a class="topbar-btn cal-nav" href="<?= e(url('/availability', ['month' => $prevMonth])) ?>" aria-label="Previous month"><?= icon('back') ?></a>
        <?php else: ?><span class="cal-nav"></span><?php endif; ?>
        <h2><?= e($label) ?></h2>
        <?php if ($nextMonth): ?>
            <a class="topbar-btn cal-nav" href="<?= e(url('/availability', ['month' => $nextMonth])) ?>" aria-label="Next month"><?= icon('next') ?></a>
        <?php else: ?><span class="cal-nav"></span><?php endif; ?>
    </div>

    <div class="cal-grid" role="grid">
        <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $i => $dow): ?>
            <div class="cal-dow" aria-hidden="true"><?= $dow ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $offset; $i++): ?><span class="cal-day is-off"></span><?php endfor; ?>
        <?php for ($d = 1; $d <= $lastDay; $d++):
            $date = sprintf('%04d-%02d-%02d', $year, $mon, $d);
            $closed = $map[$date] !== null;
            $classes = 'cal-day' . ($closed ? ' is-unavailable' : '') . ($date === $selected ? ' is-selected' : '') . ($date === $today ? ' is-today' : '');
            $aria = date('D, M j', strtotime($date)) . ($closed ? ', not available' : ', available') . ($date === $selected ? ', selected' : '');
        ?>
            <a class="<?= e($classes) ?>" href="<?= e(url('/availability', ['month' => $month, 'date' => $date])) ?>"
               aria-label="<?= e($aria) ?>"<?= $date === $selected ? ' aria-current="date"' : '' ?>><?= $d ?></a>
        <?php endfor; ?>
    </div>

    <div class="legend" aria-hidden="true">
        <span><i class="swatch"></i> Available</span>
        <span><i class="swatch swatch-off"></i> Not available</span>
        <span><i class="swatch swatch-sel"></i> Selected</span>
    </div>
</section>

<section class="section" aria-live="polite">
    <?php if ($selected && $reason === null): ?>
        <div class="card result result-ok">
            <p class="card-title"><?= e(date_short($selected)) ?> · <span class="tag tag-ok"><?= icon('check', 'icon icon-s') ?> Available</span></p>
            <p class="mb-0">This date is open for booking.</p>
        </div>
        <div class="action-bar">
            <a class="btn btn-primary btn-block btn-lg" href="<?= e(url('/book', ['date' => $selected])) ?>">Continue to booking details</a>
        </div>
    <?php elseif ($selected): ?>
        <div class="alert alert-danger" role="alert"><?= icon('alert') ?>
            <div><strong><?= e(date_short($selected)) ?></strong>
            <p class="mb-0">Sorry, this date is not available. Please choose another date.</p></div>
        </div>
    <?php else: ?>
        <div class="empty"><p>Tap a date to see if it is open.</p></div>
    <?php endif; ?>
</section>
