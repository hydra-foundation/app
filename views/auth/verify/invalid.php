<?php /** @var \App\View\Template $this */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Verify email · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Link expired</h1>
            <p>This verification link has expired, or the address it was sent to has changed.</p>
            <p class="mb-0">Sign in to send a new one.</p>
        </div>
    </div>
</div>
