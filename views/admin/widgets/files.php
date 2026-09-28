<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var int $files */ ?>
<?php /** @var string $size */ ?>
<?php /** @var list<array{disk: string, files: int, size: string}> $disks */ ?>
<?php /** @var int $orphans */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var string|null $orphansUrl */ ?>
<?php /** @var list<array{name: string, size: string, modified_at: string, url: string|null}> $newest */ ?>
<?php $link = function (?string $href, string $text): string {
    if ($href === null) {
        return $this->e($text);
    }

    return '<a href="' . $this->e($href) . '" hx-nonce="' . $this->e($this->cspNonce()) . '" hx-get="' . $this->e($href)
        . '" hx-target="#admin-frame" hx-push-url="true">' . $this->e($text) . '</a>';
}; ?>
<ul class="widget-rows">
    <?php foreach ($disks as $disk): ?>
        <li>
            <?= $link($url === null ? null : $url . '?disk=' . rawurlencode($disk['disk']), ucfirst($disk['disk'])) ?>
            <span class="widget-caption"><?= $this->e($disk['files'] . ($disk['files'] === 1 ? ' file' : ' files')) ?> &middot; <?= $this->e($disk['size']) ?></span>
        </li>
    <?php endforeach ?>
    <li>
        <?= $link($orphansUrl, 'Orphans') ?>
        <span class="widget-caption"><?= $this->e($orphans . ($orphans === 1 ? ' orphan' : ' orphans')) ?><?php if ($orphans === 0): ?> &middot; nothing to clear<?php endif ?></span>
    </li>
    <?php foreach ($newest as $file): ?>
        <li>
            <?= $link($file['url'], $file['name']) ?>
            <span class="widget-caption"><?= $this->e($file['size']) ?></span>
        </li>
    <?php endforeach ?>
</ul>
