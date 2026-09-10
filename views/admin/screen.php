<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var string $body */ ?>
<?php /** @var string|null $toolbar */ ?>
<?php /** @var array<string, mixed> $data */ ?>
<?php $this->extends('layouts/admin') ?>

<?php $this->start('title') ?><?= $this->e($screen->title) ?> · Admin<?php $this->stop() ?>

<?= $this->partial('admin/partials/frame', ['screen' => $screen, 'body' => $body, 'toolbar' => $toolbar, 'data' => $data]) ?>
