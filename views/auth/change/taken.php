<?php /** @var \App\View\Template $this */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Change email · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Address in use</h1>
            <p>Another account took this address after the link was sent. Your address has not changed.</p>
            <p class="mb-0"><a href="/admin/settings/account">Back to Settings</a></p>
        </div>
    </div>
</div>
