<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php $this->extends('layouts/base') ?>

<?php /* The admin's own sheet and script, loaded only on admin screens. Both
   are the package's and are served by it, so an upgrade that adds a field type
   or a control brings the styling for it with no file to re-copy here. Add a
   sheet of your own after this one to override any of it. */ ?>
<?php $this->start('meta') ?><link rel="stylesheet" href="/admin/assets/stylesheet" /><script src="/admin/assets/script" defer></script><?php $this->stop() ?>

<?= $this->partial('admin/partials/shell', [
    'screen' => $screen,
    'content' => $this->section('content'),
    'footer' => trim($this->partial('partials/version')),
]) ?>
