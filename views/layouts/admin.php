<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('meta') ?><link rel="stylesheet" href="/css/admin.css" /><?php $this->stop() ?>

<?= $this->partial('admin/partials/shell', ['screen' => $screen, 'content' => $this->section('content')]) ?>
