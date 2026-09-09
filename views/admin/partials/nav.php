<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var bool $oob */ ?>
<ul class="nav nav-pills flex-column gap-1" id="admin-nav"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
    <?php foreach ($screen->navigation as $item): ?>
        <li class="nav-item">
            <a class="nav-link<?= $item['active'] ? ' active' : '' ?>"
               href="<?= $this->e($item['url']) ?>"
               hx-get="<?= $this->e($item['url']) ?>"
               hx-target="#admin-frame"
               hx-push-url="true">
                <?php if ($item['icon'] !== null): ?><i class="bi bi-<?= $this->e($item['icon']) ?> me-1"></i><?php endif ?>
                <?= $this->e($item['title']) ?>
            </a>
        </li>
    <?php endforeach ?>
</ul>
