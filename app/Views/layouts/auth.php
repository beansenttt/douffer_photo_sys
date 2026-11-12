<?php
/** Layout: login / logged-out admin pages (blue, centered card, no navigation). */
?>
<?= partial('partials/head', ['title' => $title ?? 'Log in', 'theme' => 'admin']) ?>
<body class="theme-admin theme-auth">
<main id="main" class="auth-wrap" tabindex="-1">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>
</body>
</html>
