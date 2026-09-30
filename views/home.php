<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \DateTimeImmutable|null $demo */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Home · Hydra<?php $this->stop() ?>

<div class="page">
    <h1 class="page-title">Welcome to Hydra</h1>
    <p class="page-lead">A small PHP framework created by Will Hleucka.</p>
    <?php if ($demo === null): ?>
    <p class="page-actions"><a href="/login">Sign in</a></p>
    <?php else: ?>
    <?= $this->partial('partials/stream-demo', ['at' => $demo]) ?>
    <?php endif ?>
</div>
