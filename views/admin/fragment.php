<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var string $body */ ?>
<?php /** @var string|null $toolbar */ ?>
<?php /** @var array<string, mixed> $data */ ?>
<?php /* htmx lifts the title out of this head block and drops the block itself. */ ?>
<head><title><?= $this->e($screen->title) ?> · Admin</title></head>
<?= $this->partial('admin/partials/frame', ['screen' => $screen, 'body' => $body, 'toolbar' => $toolbar, 'data' => $data]) ?>
<?= $this->partial('admin/partials/nav', ['screen' => $screen, 'oob' => true]) ?>
