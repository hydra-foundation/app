<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php $this->extends('layouts/base') ?>

<div class="admin d-flex align-items-stretch">
    <?= $this->partial('admin/partials/sidebar', ['screen' => $screen]) ?>
    <div id="admin-frame" class="admin-frame flex-grow-1 p-4"><?= $this->section('content') ?></div>
</div>
