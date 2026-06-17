<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\AccountViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Dashboard · Hydra<?php $this->stop() ?>

<h1>Dashboard</h1>
<p>Signed in as <strong><?= $this->e($vm->username) ?></strong>. This page is behind the auth guard.</p>

<form method="post" action="/logout">
    <?= $this->csrf() ?>
    <button type="submit">Sign out</button>
</form>
