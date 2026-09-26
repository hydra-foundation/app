<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var int $waiting */ ?>
<?php /** @var int $held */ ?>
<?php /** @var int $failed */ ?>
<?php /** @var string|\Hydra\View\HtmlView|null $oldest */ ?>
<ul class="widget-rows">
    <?php foreach ([['Waiting', $waiting, '/admin/jobs'], ['Held by a worker', $held, '/admin/jobs']] as [$label, $count, $href]): ?>
        <li>
            <a href="<?= $href ?>"
               hx-nonce="<?= $this->e($this->cspNonce()) ?>"
               hx-get="<?= $href ?>"
               hx-target="#admin-frame"
               hx-push-url="true"><?= $this->e($label) ?></a>
            <span class="widget-caption"><?= $this->e((string) $count) ?></span>
        </li>
    <?php endforeach ?>
    <li>
        <a href="/admin/failed-jobs"
           hx-nonce="<?= $this->e($this->cspNonce()) ?>"
           hx-get="/admin/failed-jobs"
           hx-target="#admin-frame"
           hx-push-url="true">Failed</a>
        <span class="widget-caption"><?= $this->e((string) $failed) ?><?php if ($oldest === null): ?> &middot; Nothing has failed.<?php else: ?> &middot; oldest <?= $this->e($oldest) ?><?php endif ?></span>
    </li>
</ul>
