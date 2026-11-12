<?php
/** Flash messages as toasts. aria-live so screen readers announce them. */
$flashes = \App\Core\Flash::pull();
?>
<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false">
<?php foreach ($flashes as $f): ?>
    <div class="toast toast-<?= e($f['type']) ?>"<?= $f['type'] === 'error' ? ' role="alert"' : ' role="status"' ?>>
        <span><?= e($f['message']) ?></span>
        <button type="button" class="toast-close" data-dismiss aria-label="Dismiss"><?= icon('close') ?></button>
    </div>
<?php endforeach; ?>
</div>
