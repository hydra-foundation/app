<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var string $body */ ?>
<?php /** @var string|null $toolbar */ ?>
<?php /** @var array<string, mixed> $data */ ?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <?php foreach ($screen->breadcrumbs as $crumb): ?>
            <li class="breadcrumb-item<?= $crumb['url'] === null ? ' active' : '' ?>">
                <?php if ($crumb['url'] === null): ?>
                    <?= $this->e($crumb['label']) ?>
                <?php else: ?>
                    <a href="<?= $this->e($crumb['url']) ?>"
                       hx-get="<?= $this->e($crumb['url']) ?>"
                       hx-target="#admin-frame"
                       hx-push-url="true"><?= $this->e($crumb['label']) ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>

<h1 class="h3 mb-3"><?= $this->e($screen->title) ?></h1>

<?php if ($screen->notice !== null): ?>
    <div class="alert alert-<?= $this->e($screen->notice->style()) ?>"
         role="<?= $this->e($screen->notice->role()) ?>"><?= $this->e($screen->notice->text) ?></div>
<?php endif ?>

<?php if ($toolbar !== null): ?><?= $this->partial($toolbar, $data) ?><?php endif ?>

<div id="admin-body"><?= $this->partial($body, $data) ?></div>
