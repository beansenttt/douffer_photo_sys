<?php
/**
 * Layout: crew (A18). A single screen, no navigation; view-only.
 * Variables: $title, $back (optional), $content.
 */
use App\Core\Auth;
?>
<?= partial('partials/head', ['title' => $title ?? 'My Events', 'theme' => 'admin']) ?>
<body class="theme-admin theme-crew">
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <?php if (!empty($back)): ?>
        <a class="topbar-btn" href="<?= e(url($back)) ?>" aria-label="Back"><?= icon('back') ?></a>
    <?php else: ?>
        <span class="topbar-logo" aria-hidden="true"><?= icon('camera') ?></span>
    <?php endif; ?>
    <h1 class="topbar-title"><?= e($title ?? 'My Events') ?></h1>
    <?php if (Auth::check()): ?>
    <form method="post" action="<?= e(url('/admin/logout')) ?>" class="topbar-end">
        <?= csrf_field() ?>
        <button type="submit" class="topbar-btn" aria-label="Log out"><?= icon('logout') ?></button>
    </form>
    <?php endif; ?>
</header>
<main id="main" class="page page-narrow" tabindex="-1">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>
</body>
</html>
