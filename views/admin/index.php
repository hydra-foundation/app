<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\AdminViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Admin · Hydra<?php $this->stop() ?>

<div class="container-fluid">
    <h1>Admin</h1>
    <p>Hello, <?=$vm->currentUser->username?>! You are now signed in.</p>
    <p><a class="btn btn-primary" hx-post="/logout">Sign out</a></p>
</div>
