<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php $this->extends('layouts/base') ?>

<?php /* The admin's own sheet and script, loaded only on admin screens. Both
   travel with admin.css if it ever moves into the package. */ ?>
<?php $this->start('meta') ?><link rel="stylesheet" href="/css/admin.css" /><script src="/js/admin.js" defer></script><?php $this->stop() ?>

<?= $this->partial('admin/partials/shell', ['screen' => $screen, 'content' => $this->section('content')]) ?>
