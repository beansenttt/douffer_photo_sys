<?php /* Change own password (any role). Strong-password rule is enforced on the server. */ ?>
<form method="post" action="<?= e(url('/account/password')) ?>" class="card stack" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required<?= invalid_attr('current_password') ?>>
        <?= error_html('current_password') ?>
    </div>
    <div class="field">
        <label for="password">New password</label>
        <div class="input-group">
            <input id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="pw-hint"<?= invalid_attr('password') ?>>
            <button type="button" class="btn btn-ghost input-addon" data-toggle-password="password" aria-pressed="false">Show</button>
        </div>
        <p class="hint" id="pw-hint">At least 8 characters, with letters and a number.</p>
        <?= error_html('password') ?>
    </div>
    <div class="field">
        <label for="password_confirm">Type the new password again</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" enterkeyhint="done" required<?= invalid_attr('password_confirm') ?>>
        <?= error_html('password_confirm') ?>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Save new password</button>
</form>
