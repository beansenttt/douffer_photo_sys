<?php /* Error page (403, 404, 405, 419, 429, 500). Friendly text, never a stack trace. */ ?>
<div class="empty error-page">
    <?= icon($status === 403 ? 'shield' : 'alert', 'icon icon-xl') ?>
    <p class="error-code"><?= (int) $status ?></p>
    <h2 class="h2"><?= e($title) ?></h2>
    <?php if ($message !== ''): ?>
        <p><?= e($message) ?></p>
    <?php elseif ($status === 403): ?>
        <p>Your account is not allowed to open this page. Ask the owner if you need access.</p>
    <?php elseif ($status === 404): ?>
        <p>The page you are looking for does not exist or was moved.</p>
    <?php else: ?>
        <p>Please go back and try again.</p>
    <?php endif; ?>
    <?php
    $home = '/';
    if (\App\Core\Auth::check()) {
        $home = \App\Core\Auth::homeFor(\App\Core\Auth::user());
    }
    ?>
    <a class="btn btn-primary" href="<?= e(url($home)) ?>">Go to the home screen</a>
</div>
