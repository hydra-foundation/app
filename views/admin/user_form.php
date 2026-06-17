<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\UserFormViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?><?= $vm->isEdit() ? 'Edit user' : 'New user' ?> · Hydra<?php $this->stop() ?>

<h1><?= $vm->isEdit() ? 'Edit user' : 'New user' ?></h1>

<form method="post" action="<?= $this->e($vm->action()) ?>">
    <?= $this->csrf() ?>

    <label>
        Username
        <input type="text" name="username" value="<?= $this->e($vm->username) ?>" autocomplete="off" autofocus>
    </label>
    <?php if ($vm->error('username') !== null): ?>
        <p class="error" role="alert"><?= $this->e($vm->error('username')) ?></p>
    <?php endif ?>

    <?php if (!$vm->isEdit()): ?>
        <label>
            Password
            <input type="password" name="password" autocomplete="new-password">
        </label>
        <?php if ($vm->error('password') !== null): ?>
            <p class="error" role="alert"><?= $this->e($vm->error('password')) ?></p>
        <?php endif ?>
    <?php endif ?>

    <label>
        Role
        <select name="role">
            <option value="user"<?= $vm->role === 'user' ? ' selected' : '' ?>>user</option>
            <option value="admin"<?= $vm->role === 'admin' ? ' selected' : '' ?>>admin</option>
        </select>
    </label>
    <?php if ($vm->error('role') !== null): ?>
        <p class="error" role="alert"><?= $this->e($vm->error('role')) ?></p>
    <?php endif ?>

    <button type="submit"><?= $vm->isEdit() ? 'Save changes' : 'Create user' ?></button>
</form>

<p><a href="/admin">Back to users</a></p>
