<?php
/* Screen A17: Users & Access (/admin/users, Owner only).
   Vars: $users $permissions */
$currentUserId = \App\Core\Auth::id();
?>

<details class="card section">
    <summary class="btn btn-outline btn-block"><?= icon('plus') ?> Add user account</summary>
    <form method="post" action="<?= e(url('/admin/users')) ?>" class="stack section" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="_perms_submitted" value="1">

        <div class="field">
            <label for="new_user_name">Full name <span class="req">*</span></label>
            <input id="new_user_name" name="name" type="text" required maxlength="100" value="<?= e(old('name')) ?>" placeholder="e.g. Marco Reyes"<?= invalid_attr('name') ?>>
            <?= error_html('name') ?>
        </div>

        <div class="field">
            <label for="new_username">Username <span class="req">*</span></label>
            <input id="new_username" name="username" type="text" required maxlength="50" value="<?= e(old('username')) ?>" placeholder="lowercase letters and numbers, e.g. staff2"<?= invalid_attr('username') ?>>
            <?= error_html('username') ?>
        </div>

        <div class="field">
            <label for="new_email">Email <span class="small muted">(optional)</span></label>
            <input id="new_email" name="email" type="email" maxlength="150" value="<?= e(old('email')) ?>" placeholder="Optional"<?= invalid_attr('email') ?>>
            <?= error_html('email') ?>
        </div>

        <div class="field">
            <label for="new_role">Role <span class="req">*</span></label>
            <select id="new_role" name="role" required>
                <option value="staff"<?= old('role', 'staff') === 'staff' ? ' selected' : '' ?>>Staff (studio assistant, custom permissions)</option>
                <option value="crew"<?= old('role') === 'crew' ? ' selected' : '' ?>>Crew (on-call photographer/videographer, view-only A18)</option>
                <option value="owner"<?= old('role') === 'owner' ? ' selected' : '' ?>>Owner (full access)</option>
            </select>
            <?= error_html('role') ?>
        </div>

        <div class="field">
            <label for="new_password">Initial password <span class="req">*</span> <span class="small muted">(min 8 chars, letters + number)</span></label>
            <div class="field-inline">
                <input id="new_password" name="password" type="password" required minlength="8" placeholder="e.g. Studio@2026"<?= invalid_attr('password') ?>>
                <button type="button" class="btn btn-small btn-outline" data-toggle-password="new_password">Show</button>
            </div>
            <?= error_html('password') ?>
        </div>

        <fieldset class="stack-s">
            <legend class="eyebrow">Staff permissions (used when role is Staff)</legend>
            <?php foreach ($permissions as $key => [$label, $default]): ?>
                <label class="check">
                    <input type="checkbox" name="perm_<?= e($key) ?>" value="1"<?= $default ? ' checked' : '' ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="field">
            <label for="new_crew_type">Crew specialty (used when role is Crew)</label>
            <select id="new_crew_type" name="crew_type">
                <option value="photographer">Photographer</option>
                <option value="videographer">Videographer</option>
                <option value="other">Other</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Create user</button>
    </form>
</details>

<div class="stack section">
    <?php foreach ($users as $u): ?>
        <?php
        $uid = (int) $u['id'];
        $isActive = (int) $u['is_active'] === 1;
        $role = (string) $u['role'];
        ?>
        <article class="card stack-s" id="user-<?= $uid ?>">
            <div class="row-between">
                <div>
                    <span class="eyebrow"><?= e(ucfirst($role)) ?><?= !empty($u['crew_type']) ? ' · ' . e(ucfirst((string) $u['crew_type'])) : '' ?></span>
                    <h2 class="card-title"><?= e($u['name']) ?></h2>
                    <p class="card-meta">
                        <strong>@<?= e($u['username']) ?></strong>
                        <?php if (!empty($u['email'])): ?>
                            · <?= e($u['email']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="row-gap">
                    <span class="tag <?= $isActive ? 'tag-confirmed' : 'tag-cancelled' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                    <?php if ((int) $u['must_change_password'] === 1): ?>
                        <span class="tag tag-pending">Default password</span>
                    <?php endif; ?>
                </div>
            </div>

            <p class="small muted">
                Last login: <?= !empty($u['last_login_at']) ? e(datetime_short($u['last_login_at'])) : 'Never' ?>
            </p>

            <?php if ($role === 'staff'): ?>
                <form method="post" action="<?= e(url('/admin/users/' . $uid . '/permissions')) ?>" class="stack-s section">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_perms_submitted" value="1">
                    <p class="eyebrow">Staff permissions</p>
                    <?php foreach ($permissions as $key => [$label, $_default]): ?>
                        <?php $col = 'perm_' . $key; ?>
                        <label class="check">
                            <input type="checkbox" name="<?= e($col) ?>" value="1"<?= (int) ($u[$col] ?? 0) === 1 ? ' checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <div>
                        <button type="submit" class="btn btn-primary btn-small">Save permissions</button>
                    </div>
                </form>
            <?php elseif ($role === 'owner'): ?>
                <p class="small muted">Owner has full access to all screens, settings, backups, and audit logs.</p>
            <?php else: ?>
                <p class="small muted">Crew accounts are restricted to Screen A18 (My Events, read-only, no payments or chats).</p>
            <?php endif; ?>

            <div class="row-gap section">
                <?php if ($uid !== $currentUserId): ?>
                    <form method="post" action="<?= e(url('/admin/users/' . $uid . '/toggle')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-small btn-outline">
                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <details>
                <summary class="btn btn-small btn-outline"><?= icon('key') ?> Reset password</summary>
                <form method="post" action="<?= e(url('/admin/users/' . $uid . '/reset-password')) ?>" class="stack-s section">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="reset_pw_<?= $uid ?>">New temporary password for @<?= e($u['username']) ?> <span class="req">*</span></label>
                        <div class="field-inline">
                            <input id="reset_pw_<?= $uid ?>" name="password" type="password" required minlength="8" placeholder="At least 8 chars, letters + number">
                            <button type="button" class="btn btn-small btn-outline" data-toggle-password="reset_pw_<?= $uid ?>">Show</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-small">Reset password</button>
                </form>
            </details>
        </article>
    <?php endforeach; ?>
</div>
