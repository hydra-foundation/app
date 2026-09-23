<?php /** @var \App\View\Template $this */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Reset password · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Link expired</h1>
            <p>This reset link has expired or has already been used.</p>
            <p class="mb-0"><a href="/forgot-password">Send a new link</a></p>
        </div>
    </div>
</div>
