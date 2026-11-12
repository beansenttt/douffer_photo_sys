<?php
/* Screen A15: Packages & Services Catalog (/admin/packages, Owner only).
   Vars: $tab $categories $photoVideoPackages $otherServices $addons $editingPackage */
?>

<nav class="chips section" aria-label="Catalog sections">
    <a class="chip<?= $tab === 'packages' ? ' is-active' : '' ?>" href="<?= e(url('/admin/packages', ['tab' => 'packages'])) ?>"<?= $tab === 'packages' ? ' aria-current="page"' : '' ?>>
        Photo &amp; video (<?= count($photoVideoPackages) ?>)
    </a>
    <a class="chip<?= $tab === 'other' ? ' is-active' : '' ?>" href="<?= e(url('/admin/packages', ['tab' => 'other'])) ?>"<?= $tab === 'other' ? ' aria-current="page"' : '' ?>>
        Other services (<?= count($otherServices) ?>)
    </a>
    <a class="chip<?= $tab === 'addons' ? ' is-active' : '' ?>" href="<?= e(url('/admin/packages', ['tab' => 'addons'])) ?>"<?= $tab === 'addons' ? ' aria-current="page"' : '' ?>>
        Add-ons (<?= count($addons) ?>)
    </a>
</nav>

<?php if ($editingPackage): ?>
    <section class="card stack section" aria-labelledby="edit-pkg-title">
        <div class="row-between">
            <h2 class="card-title" id="edit-pkg-title">Edit: <?= e($editingPackage['name']) ?></h2>
            <a class="btn btn-small btn-outline" href="<?= e(url('/admin/packages', ['tab' => $editingPackage['category'] === 'other' ? 'other' : 'packages'])) ?>">Cancel</a>
        </div>
        <form method="post" action="<?= e(url('/admin/packages/' . (int) $editingPackage['id'])) ?>" class="stack" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="edit_name">Package / service name <span class="req">*</span></label>
                <input id="edit_name" name="name" type="text" required maxlength="100" value="<?= e(old('name', $editingPackage['name'])) ?>"<?= invalid_attr('name') ?>>
                <?= error_html('name') ?>
            </div>
            <div class="field">
                <label for="edit_category">Category <span class="req">*</span></label>
                <select id="edit_category" name="category" required>
                    <?php foreach ($categories as $val => $lbl): ?>
                        <option value="<?= e($val) ?>"<?= old('category', $editingPackage['category']) === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= error_html('category') ?>
            </div>
            <div class="field">
                <label for="edit_price">Rate (₱) <span class="small muted">(leave blank for inquire-only services)</span></label>
                <input id="edit_price" name="price" type="text" inputmode="decimal" value="<?= e(old('price', $editingPackage['price'] !== null ? number_format((float) $editingPackage['price'], 2, '.', '') : '')) ?>" placeholder="e.g. 16000.00"<?= invalid_attr('price') ?>>
                <?= error_html('price') ?>
            </div>
            <div class="field">
                <label for="edit_dp">Fixed down-payment override (₱) <span class="small muted">(optional; blank uses studio default rule)</span></label>
                <input id="edit_dp" name="down_payment_override" type="text" inputmode="decimal" value="<?= e(old('down_payment_override', $editingPackage['down_payment_override'] !== null ? number_format((float) $editingPackage['down_payment_override'], 2, '.', '') : '')) ?>" placeholder="Leave blank for default %"<?= invalid_attr('down_payment_override') ?>>
                <?= error_html('down_payment_override') ?>
            </div>
            <div class="field">
                <label for="edit_desc">Short description</label>
                <input id="edit_desc" name="description" type="text" maxlength="255" value="<?= e(old('description', (string) $editingPackage['description'])) ?>">
            </div>
            <div class="field">
                <label for="edit_inc">Inclusions <span class="small muted">(one item per line)</span></label>
                <textarea id="edit_inc" name="inclusions" rows="5" maxlength="4000"><?= e(old('inclusions', (string) $editingPackage['inclusions'])) ?></textarea>
            </div>
            <div class="field">
                <label for="edit_sort">Sort order</label>
                <input id="edit_sort" name="sort_order" type="number" value="<?= e(old('sort_order', (string) $editingPackage['sort_order'])) ?>">
            </div>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1"<?= (int) old('is_active', (string) $editingPackage['is_active']) === 1 ? ' checked' : '' ?>>
                    <span>Active (visible on client portal C1)</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save package changes</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($tab !== 'addons'): ?>
    <details class="card section">
        <summary class="btn btn-outline btn-block"><?= icon('plus') ?> Add new <?= $tab === 'other' ? 'other service' : 'package' ?></summary>
        <form method="post" action="<?= e(url('/admin/packages')) ?>" class="stack section" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="new_pkg_name">Name <span class="req">*</span></label>
                <input id="new_pkg_name" name="name" type="text" required maxlength="100" value="<?= e(old('name')) ?>" placeholder="e.g. Prenup + Wedding Package"<?= invalid_attr('name') ?>>
                <?= error_html('name') ?>
            </div>
            <div class="field">
                <label for="new_pkg_cat">Category <span class="req">*</span></label>
                <select id="new_pkg_cat" name="category" required>
                    <?php
                    $defaultCat = $tab === 'other' ? 'other' : 'photo_video';
                    foreach ($categories as $val => $lbl):
                    ?>
                        <option value="<?= e($val) ?>"<?= old('category', $defaultCat) === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="new_pkg_price">Rate (₱) <?= $tab !== 'other' ? '<span class="req">*</span>' : '<span class="small muted">(optional)</span>' ?></label>
                <input id="new_pkg_price" name="price" type="text" inputmode="decimal" value="<?= e(old('price')) ?>" placeholder="e.g. 12000.00"<?= invalid_attr('price') ?>>
                <?= error_html('price') ?>
            </div>
            <div class="field">
                <label for="new_pkg_dp">Fixed down-payment override (₱) <span class="small muted">(optional)</span></label>
                <input id="new_pkg_dp" name="down_payment_override" type="text" inputmode="decimal" value="<?= e(old('down_payment_override')) ?>" placeholder="Leave blank to use studio default %">
                <?= error_html('down_payment_override') ?>
            </div>
            <div class="field">
                <label for="new_pkg_desc">Short description</label>
                <input id="new_pkg_desc" name="description" type="text" maxlength="255" value="<?= e(old('description')) ?>" placeholder="Shown on package card">
            </div>
            <div class="field">
                <label for="new_pkg_inc">Inclusions <span class="small muted">(one item per line)</span></label>
                <textarea id="new_pkg_inc" name="inclusions" rows="4" maxlength="4000" placeholder="1 photographer, up to 6 hours&#10;All edited photos"><?= e(old('inclusions')) ?></textarea>
            </div>
            <div class="field">
                <label for="new_pkg_sort">Sort order</label>
                <input id="new_pkg_sort" name="sort_order" type="number" value="<?= e(old('sort_order', $tab === 'other' ? '20' : '5')) ?>">
            </div>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Active (visible on client portal C1)</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create package</button>
        </form>
    </details>
<?php endif; ?>

<?php if ($tab === 'packages'): ?>
    <div class="stack section">
        <?php foreach ($photoVideoPackages as $pkg): ?>
            <?php
            $pid = (int) $pkg['id'];
            $inclusions = \App\Models\Package::inclusions($pkg['inclusions']);
            $isActive = (int) $pkg['is_active'] === 1;
            ?>
            <article class="card stack-s" id="package-<?= $pid ?>">
                <div class="row-between">
                    <div>
                        <span class="eyebrow"><?= e($categories[$pkg['category']] ?? $pkg['category']) ?></span>
                        <h2 class="card-title"><?= e($pkg['name']) ?></h2>
                    </div>
                    <span class="tag <?= $isActive ? 'tag-confirmed' : 'tag-cancelled' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </div>

                <div class="card-row">
                    <span>Package rate</span>
                    <strong class="money"><?= $pkg['price'] !== null ? e(peso($pkg['price'])) : 'Ask the studio' ?></strong>
                </div>
                <div class="card-row">
                    <span>Down payment rule</span>
                    <span>
                        <?php if ($pkg['down_payment_override'] !== null): ?>
                            Fixed override: <strong><?= e(peso($pkg['down_payment_override'])) ?></strong>
                        <?php else: ?>
                            Default studio rule (<?= e(\App\Core\Settings::get('down_payment_value', '30')) ?><?= \App\Core\Settings::get('down_payment_mode') === 'percent' ? '%' : ' ₱' ?>)
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($pkg['description'])): ?>
                    <p class="small muted"><?= e($pkg['description']) ?></p>
                <?php endif; ?>

                <?php if (!empty($inclusions)): ?>
                    <ul class="bullet-list">
                        <?php foreach ($inclusions as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="row-gap section">
                    <a class="btn btn-small btn-outline" href="<?= e(url('/admin/packages', ['tab' => 'packages', 'edit' => $pid])) ?>">Edit</a>
                    <form method="post" action="<?= e(url('/admin/packages/' . $pid . '/toggle')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-small btn-outline">
                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php elseif ($tab === 'other'): ?>
    <p class="small muted section">Other services appear as chips on Screen C1 and invite clients to message the studio for custom quotes.</p>
    <div class="stack section">
        <?php foreach ($otherServices as $srv): ?>
            <?php
            $sid = (int) $srv['id'];
            $isActive = (int) $srv['is_active'] === 1;
            ?>
            <article class="card stack-s" id="package-<?= $sid ?>">
                <div class="row-between">
                    <div>
                        <h2 class="card-title"><?= e($srv['name']) ?></h2>
                        <p class="small muted"><?= e($srv['description'] ?: 'Please message the studio to ask about this service.') ?></p>
                    </div>
                    <span class="tag <?= $isActive ? 'tag-confirmed' : 'tag-cancelled' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="row-gap">
                    <a class="btn btn-small btn-outline" href="<?= e(url('/admin/packages', ['tab' => 'other', 'edit' => $sid])) ?>">Edit</a>
                    <form method="post" action="<?= e(url('/admin/packages/' . $sid . '/toggle')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-small btn-outline">
                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <section class="card stack section">
        <h2 class="card-title">Add-on services</h2>
        <p class="small muted">Optional extras that can be added to a booking (e.g. extra hour, same-day edit video, extra prints).</p>

        <form method="post" action="<?= e(url('/admin/packages/addons')) ?>" class="stack-s section" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="addon_name">Add-on name <span class="req">*</span></label>
                <input id="addon_name" name="name" type="text" required maxlength="100" value="<?= e(old('name')) ?>" placeholder="e.g. Drone aerial shots">
            </div>
            <div class="field">
                <label for="addon_price">Price (₱) <span class="req">*</span></label>
                <input id="addon_price" name="price" type="text" inputmode="decimal" required value="<?= e(old('price')) ?>" placeholder="e.g. 2500.00">
            </div>
            <input type="hidden" name="is_active" value="1">
            <button type="submit" class="btn btn-primary btn-small"><?= icon('plus') ?> Add add-on</button>
        </form>
    </section>

    <div class="stack section">
        <?php foreach ($addons as $addon): ?>
            <?php
            $aid = (int) $addon['id'];
            $isActive = (int) $addon['is_active'] === 1;
            ?>
            <article class="card stack-s" id="addon-<?= $aid ?>">
                <div class="row-between">
                    <div>
                        <h3 class="card-title"><?= e($addon['name']) ?></h3>
                        <p class="money"><strong><?= e(peso($addon['price'])) ?></strong></p>
                    </div>
                    <span class="tag <?= $isActive ? 'tag-confirmed' : 'tag-cancelled' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="row-gap">
                    <form method="post" action="<?= e(url('/admin/packages/addons/' . $aid . '/toggle')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-small btn-outline">
                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </div>
                <details>
                    <summary class="btn btn-small btn-outline">Edit add-on</summary>
                    <form method="post" action="<?= e(url('/admin/packages/addons/' . $aid)) ?>" class="stack-s section">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="edit_addon_name_<?= $aid ?>">Name</label>
                            <input id="edit_addon_name_<?= $aid ?>" name="name" type="text" required maxlength="100" value="<?= e($addon['name']) ?>">
                        </div>
                        <div class="field">
                            <label for="edit_addon_price_<?= $aid ?>">Price (₱)</label>
                            <input id="edit_addon_price_<?= $aid ?>" name="price" type="text" inputmode="decimal" required value="<?= e(number_format((float) $addon['price'], 2, '.', '')) ?>">
                        </div>
                        <input type="hidden" name="is_active" value="<?= $isActive ? '1' : '0' ?>">
                        <button type="submit" class="btn btn-primary btn-small">Save add-on</button>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
