<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php $this->extends('layouts/base') ?>

<?= $this->partial('admin/partials/shell', ['screen' => $screen, 'content' => $this->section('content')]) ?>
