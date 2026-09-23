<?php /** @var \App\View\Template $this */ ?>
<?php /** @var string $email */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Email verified · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Email verified</h1>
            <p><?= $this->e($email) ?> is confirmed as your address.</p>
            <p class="mb-0"><a href="/admin">Continue</a></p>
        </div>
    </div>
</div>
