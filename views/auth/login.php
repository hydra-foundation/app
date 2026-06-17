<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Hydra<?php $this->stop() ?>

<h1>Sign in</h1>

<form method="post" action="/login">
    <?= $this->csrf() ?>

    <?php if ($vm->hasErrors()): ?>
        <ul class="error" role="alert">
            <?php foreach ($vm->messages() as $message): ?>
                <li><?= $this->e($message) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <label>
        Username
        <input type="text" name="username" value="<?= $this->e($vm->username) ?>" autocomplete="username" autofocus>
    </label>

    <label>
        Password
        <input type="password" name="password" autocomplete="current-password">
    </label>

    <button type="submit">Sign in</button>
</form>
