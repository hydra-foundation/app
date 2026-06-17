<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\AdminViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Admin · Hydra<?php $this->stop() ?>

<h1>Admin</h1>

<?php if ($vm->hasStatus()): ?>
    <p class="status" role="status"><?= $this->e((string) $vm->status) ?></p>
<?php endif ?>

<p>You hold the admin role, so the gate let you in. <?= $this->e((string) $vm->count()) ?> user(s):</p>

<p><a href="/admin/users/new">New user</a></p>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($vm->users as $user): ?>
            <tr>
                <td><?= $this->e((string) $user->id) ?></td>
                <td><?= $this->e($user->username) ?></td>
                <td><?= $this->e($user->role) ?></td>
                <td>
                    <?php if ($vm->isSelf($user)): ?>
                        <em>you</em>
                    <?php else: ?>
                        <a href="<?= sprintf('/admin/users/%s/edit', $this->e((string) $user->id)) ?>">Edit</a>
                        <form method="post" action="<?= sprintf('/admin/users/%s/delete', $this->e((string) $user->id)) ?>" onsubmit=" return confirm('Delete <?= $this->e($user->username) ?>?')">
                            <?= $this->csrf() ?>
                            <button type="submit">Delete</button>
                        </form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>

<p><a href="/dashboard">Back to dashboard</a></p>
