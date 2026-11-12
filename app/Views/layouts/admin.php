<?php
/**
 * Layout: admin side (blue). Owner and staff.
 * Phone: top bar + bottom nav (5 items). >= 1024px: the nav becomes a left sidebar.
 * Variables: $title, $back (optional app path), $actions (optional extra topbar HTML), $content.
 */
use App\Core\Auth;
use App\Models\Message;

$unread = Message::unreadForAdmin();
$nav = [
    ['/admin', 'Home', 'home', nav_active('/admin', true)],
    ['/admin/calendar', 'Calendar', 'calendar', nav_active('/admin/calendar') || nav_active('/admin/crew')],
    ['/admin/bookings', 'Bookings', 'list', nav_active('/admin/bookings') || nav_active('/admin/payments')],
    ['/admin/projects', 'Projects', 'folder', nav_active('/admin/projects')],
    ['/admin/more', 'More', 'more', nav_active('/admin/more') || nav_active('/admin/clients') || nav_active('/admin/packages')
        || nav_active('/admin/reports') || nav_active('/admin/users') || nav_active('/admin/settings')
        || nav_active('/admin/logs') || nav_active('/admin/messages') || nav_active('/account')],
];
?>
<?= partial('partials/head', ['title' => $title ?? 'Admin', 'theme' => 'admin', 'scripts' => $scripts ?? []]) ?>
<body class="theme-admin has-sidebar"<?= Auth::can('manage_messages') ? ' data-poll-unread' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <?php if (!empty($back)): ?>
        <a class="topbar-btn" href="<?= e(url($back)) ?>" aria-label="Back"><?= icon('back') ?></a>
    <?php else: ?>
        <span class="topbar-logo" aria-hidden="true"><?= icon('camera') ?></span>
    <?php endif; ?>
    <h1 class="topbar-title"><?= e($title ?? 'Admin') ?></h1>
    <?= $actions ?? '' ?>
    <?php if (Auth::can('manage_messages')): ?>
        <a class="topbar-btn topbar-end" href="<?= e(url('/admin/messages')) ?>" aria-label="Messages, <?= (int) $unread ?> unread">
            <?= icon('chat') ?>
            <span class="badge" data-unread-badge<?= $unread > 0 ? '' : ' hidden' ?>><?= (int) $unread ?></span>
        </a>
    <?php endif; ?>
</header>

<nav class="bottomnav bottomnav-5" aria-label="Admin">
    <div class="sidebar-brand"><?= icon('camera') ?><span>Douffer PhotoSys</span></div>
    <?php foreach ($nav as [$href, $label, $ico, $active]): ?>
        <a href="<?= e(url($href)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <div class="sidebar-user">
        <span><?= e(Auth::user()['name'] ?? '') ?> · <?= e(ucfirst((string) Auth::role())) ?></span>
    </div>
</nav>

<main id="main" class="page" tabindex="-1">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>
</body>
</html>
