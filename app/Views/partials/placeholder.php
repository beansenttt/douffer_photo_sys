<?php /* Temporary screen shown until screen $screen is built in phase $phase. */ ?>
<div class="empty">
    <span class="tag tag-info"><?= e($screen) ?></span>
    <p><strong><?= e($title) ?></strong></p>
    <p class="muted">This screen is built in Phase <?= (int) $phase ?>.</p>
</div>
