<?php /* "More" menu (admin bottom nav, item 5). Items are filtered by role/permission in the controller. */ ?>
<ul class="menu-list">
    <?php foreach ($items as [$href, $label, $ico]): ?>
        <li><a href="<?= e(url($href)) ?>"><?= icon($ico) ?><span><?= e($label) ?></span><?= icon('next', 'icon chevron') ?></a></li>
    <?php endforeach; ?>
</ul>

<form method="post" action="<?= e(url('/admin/logout')) ?>" class="section">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline btn-block"><?= icon('logout') ?> Log out</button>
</form>
<p class="muted center small">Logged in as <?= e(\App\Core\Auth::user()['username']) ?> (<?= e(ucfirst((string) \App\Core\Auth::role())) ?>)</p>
