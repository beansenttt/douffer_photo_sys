<?php /* Screen C1: Home & Packages. Package cards open a bottom sheet (works without JS via #anchor). */ ?>
<section class="hero">
    <p class="eyebrow">Nasugbu, Batangas</p>
    <h1 class="hero-title">Book your event coverage</h1>
    <p>Photo and video coverage for milestones and celebrations.</p>
    <a class="btn btn-primary btn-block btn-lg" href="<?= e(url('/availability')) ?>">Check availability / Book now</a>
</section>

<section class="section" aria-labelledby="pkg-title">
    <h2 class="h3" id="pkg-title">Packages</h2>
    <?php if (!$packages): ?>
        <div class="empty"><p>Packages are being updated. Please message the studio.</p></div>
    <?php endif; ?>
    <?php foreach ($packages as $p): ?>
        <a class="card pkg" href="#pkg-<?= (int) $p['id'] ?>" data-sheet-open="pkg-<?= (int) $p['id'] ?>">
            <span class="row-between">
                <span class="card-title"><?= e($p['name']) ?></span>
                <span class="price money"><?= e(peso($p['price'])) ?></span>
            </span>
            <span class="card-meta"><?= e($p['description']) ?></span>
            <span class="small link-like">Tap to see what is included</span>
        </a>
    <?php endforeach; ?>
</section>

<section class="section" aria-labelledby="svc-title">
    <h2 class="h3" id="svc-title">Other services (inquire)</h2>
    <div class="chips">
        <?php foreach ($services as $s): ?>
            <a class="chip" href="#svc-<?= (int) $s['id'] ?>" data-sheet-open="svc-<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<p class="small center section"><a href="<?= e(url('/privacy')) ?>">Privacy notice</a> · <a href="<?= e(url('/admin/login')) ?>">Studio login</a></p>

<?php foreach ($packages as $p): ?>
<div class="sheet" id="pkg-<?= (int) $p['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="pkg-<?= (int) $p['id'] ?>-t">
    <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
    <div class="sheet-panel">
        <h2 class="h2" id="pkg-<?= (int) $p['id'] ?>-t"><?= e($p['name']) ?></h2>
        <p class="price money"><?= e(peso($p['price'])) ?></p>
        <h3 class="h3">Inclusions</h3>
        <ul class="incl">
            <?php foreach (\App\Models\Package::inclusions($p['inclusions']) as $line): ?>
                <li><?= e($line) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="sheet-actions">
            <a class="btn btn-primary" href="<?= e(url('/availability')) ?>">Check availability / Book now</a>
            <a class="btn btn-outline" href="#" data-sheet-close>Close</a>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php foreach ($services as $s): ?>
<div class="sheet" id="svc-<?= (int) $s['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="svc-<?= (int) $s['id'] ?>-t">
    <a class="sheet-backdrop" href="#" data-sheet-close aria-label="Close"></a>
    <div class="sheet-panel">
        <h2 class="h2" id="svc-<?= (int) $s['id'] ?>-t"><?= e($s['name']) ?></h2>
        <p>Please message the studio to ask about this service.</p>
        <div class="sheet-actions">
            <a class="btn btn-primary" href="<?= e(url('/chat', ['service' => $s['name']])) ?>"><?= icon('chat') ?> Chat with the studio</a>
            <?php if ($messenger && preg_match('#^https://#', $messenger)): ?>
                <a class="btn btn-outline" href="<?= e($messenger) ?>" rel="noopener" target="_blank">Message on Facebook Messenger</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="#" data-sheet-close>Close</a>
        </div>
    </div>
</div>
<?php endforeach; ?>
