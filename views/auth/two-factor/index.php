<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\TwoFactorViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Two-factor sign in · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Two-factor sign in</h1>
            <?= $this->partial('auth/two-factor/form', ['vm' => $vm]) ?>
        </div>
    </div>
</div>
