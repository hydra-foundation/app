<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var array<string, string> $preferences */ ?>
<?= $this->partial('admin/settings/nav', ['current' => '']) ?>

<div class="settings-rows" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <?php foreach (['Username' => [$user->username, ''], 'Role' => [$user->role->label(), ''], 'Preferences set' => [(string) count($preferences), ' is-data']] as $label => [$value, $class]): ?>
    <div class="settings-row">
        <div class="settings-row-static">
            <span class="settings-row-label"><?= $this->e($label) ?></span>
            <span class="settings-row-value<?= $class ?>"><?= $this->e($value) ?></span>
        </div>
    </div>
    <?php endforeach ?>
</div>

<p class="form-text" hx-nonce="<?= $this->e($this->cspNonce()) ?>">Settings that change how the admin behaves will appear here as they are added.</p>
