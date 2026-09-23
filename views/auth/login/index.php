<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Sign in</h1>
            <?php if ($vm->status !== null): ?>
                <div class="alert alert-success" role="status"><?= $this->e($vm->status) ?></div>
            <?php endif ?>
            <?= $this->partial('auth/login/form', ['vm' => $vm]) ?>
        </div>
        <?php if (($version = trim($this->partial('partials/version'))) !== ''): ?>
            <p class="auth-version"><?= $version ?></p>
        <?php endif ?>
    </div>
</div>
