<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Hydra<?php $this->stop() ?>

<h1>Sign in</h1>

<?= $this->partial('auth/login_form', ['vm' => $vm]) ?>
