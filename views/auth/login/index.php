<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-brand">Hydra</div>
        <div class="auth-panel">
            <h1 class="auth-title">Sign in</h1>
            <?= $this->partial('auth/login/form', ['vm' => $vm]) ?>
        </div>
    </div>
</div>
