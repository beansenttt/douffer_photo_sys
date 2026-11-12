<?php
/**
 * Layout: client portal (teal). Used by C1–C6 and public pages.
 * Variables: $title, $heading (optional), $back (optional app path), $navActive, $hideFab, $content.
 */
$navActive = $navActive ?? '';
?>
<?= partial('partials/head', ['title' => $title ?? 'Douffer Photography', 'theme' => 'client', 'scripts' => $scripts ?? []]) ?>
<body class="theme-client">
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <?php if (!empty($back)): ?>
        <a class="topbar-btn" href="<?= e(url($back)) ?>" aria-label="Back"><?= icon('back') ?></a>
        <h1 class="topbar-title"><?= e($heading ?? $title) ?></h1>
    <?php else: ?>
        <a class="topbar-brand" href="<?= e(url('/')) ?>"><?= icon('camera') ?><span>Douffer Photography</span></a>
    <?php endif; ?>
    <a class="topbar-btn topbar-end" href="<?= e(url('/chat')) ?>" aria-label="Chat with the studio"><?= icon('chat') ?></a>
</header>

<main id="main" class="page" tabindex="-1">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<?php if (empty($hideFab)): ?>
<a class="fab" href="<?= e(url('/chat')) ?>"><?= icon('chat') ?><span>Chat with us</span></a>
<?php endif; ?>

<nav class="bottomnav bottomnav-3" aria-label="Main">
    <a href="<?= e(url('/')) ?>"<?= $navActive === 'home' ? ' aria-current="page"' : '' ?>><?= icon('home') ?><span>Home</span></a>
    <a href="<?= e(url('/availability')) ?>"<?= $navActive === 'availability' ? ' aria-current="page"' : '' ?>><?= icon('calendar') ?><span>Availability</span></a>
    <a href="<?= e(url('/booking')) ?>"<?= $navActive === 'booking' ? ' aria-current="page"' : '' ?>><?= icon('list') ?><span>My Booking</span></a>
</nav>
</body>
</html>
