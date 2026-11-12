<?php /* Screen A1: Login. Owner/staff go to A2, crew to A18. */ ?>
<section class="auth-card" aria-labelledby="login-title">
    <div class="auth-logo" aria-hidden="true"><?= icon('camera') ?></div>
    <h1 id="login-title" class="auth-title">Douffer PhotoSys</h1>
    <p class="muted center">Studio staff and crew log in here.</p>

    <form method="post" action="<?= e(url('/admin/login')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= e(old('username')) ?>"
                   autocomplete="username" autocapitalize="none" spellcheck="false" enterkeyhint="next" required<?= invalid_attr('username') ?>>
            <?= error_html('username') ?>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="input-group">
                <input id="password" name="password" type="password" autocomplete="current-password" enterkeyhint="go" required<?= invalid_attr('password') ?>>
                <button type="button" class="btn btn-ghost input-addon" data-toggle-password="password" aria-pressed="false">Show</button>
            </div>
            <?= error_html('password') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>
    <p class="muted center small">Forgot password? Ask the owner.</p>
    <p class="center small"><a href="<?= e(url('/')) ?>">Go to the client booking page</a></p>
</section>
