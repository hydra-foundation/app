<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var array<string, string> $preferences */ ?>
<?= $this->partial('admin/settings/nav', ['current' => '']) ?>

<dl class="admin-show">
    <dt>Username</dt>
    <dd class="type-text"><?= $this->e($user->username) ?></dd>

    <dt>Role</dt>
    <dd class="type-select"><?= $this->e($user->role) ?></dd>

    <dt>Preferences set</dt>
    <dd class="type-id"><?= $this->e(count($preferences)) ?></dd>
</dl>

<p class="form-text">Settings that change how the admin behaves will appear here as they are added.</p>
