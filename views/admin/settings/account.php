<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var \App\ViewModels\ChangePasswordViewModel $vm */ ?>
<?php /** @var \App\ViewModels\ChangeEmailViewModel $emailVm */ ?>
<?= $this->partial('admin/settings/nav', ['current' => 'account']) ?>

<dl class="admin-show" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <dt>Username</dt>
    <dd class="type-text"><?= $this->e($user->username) ?></dd>

    <dt>Email</dt>
    <dd class="type-text"><?= $this->e($user->email) ?> · <?= $user->hasVerifiedEmail() ? 'verified' : 'not verified' ?></dd>
</dl>

<form id="password-form"
      class="col-12 col-xl-6"
      method="post"
      action="/admin/settings/account"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      hx-post="/admin/settings/account"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>

    <?= $this->partial('admin/partials/errors', ['errors' => $vm->formErrors()]) ?>

    <input type="text" name="username" value="<?= $this->e($user->username) ?>" autocomplete="username" hidden>

    <?php foreach (['current_password' => ['Current password', 'current-password'], 'password' => ['New password', 'new-password'], 'password_confirmation' => ['Confirm new password', 'new-password']] as $name => [$label, $autocomplete]): ?>
    <div class="mb-3">
        <label class="form-label" for="<?= $this->e($name) ?>"><?= $this->e($label) ?></label>
        <input type="password"
               id="<?= $this->e($name) ?>"
               class="form-control<?= $vm->hasError($name) ? ' is-invalid' : '' ?>"
               name="<?= $this->e($name) ?>"
               autocomplete="<?= $this->e($autocomplete) ?>">
        <?php if ($vm->hasError($name)): ?>
        <span class="invalid-feedback d-block"><?= $this->e($vm->error($name)) ?></span>
        <?php endif ?>
    </div>
    <?php endforeach ?>

    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Change password</button>
    </div>
</form>

<form id="email-form"
      class="col-12 col-xl-6 mt-4"
      method="post"
      action="/admin/settings/account"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      hx-post="/admin/settings/account"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>
    <input type="hidden" name="intent" value="email">

    <?= $this->partial('admin/partials/errors', ['errors' => $emailVm->formErrors()]) ?>

    <?php foreach (['email' => ['New email address', 'email', 'email', 'new_email'], 'current_password' => ['Current password', 'password', 'current-password', 'email_current_password']] as $name => [$label, $type, $autocomplete, $id]): ?>
    <div class="mb-3">
        <label class="form-label" for="<?= $this->e($id) ?>"><?= $this->e($label) ?></label>
        <input type="<?= $this->e($type) ?>"
               id="<?= $this->e($id) ?>"
               class="form-control<?= $emailVm->hasError($name) ? ' is-invalid' : '' ?>"
               name="<?= $this->e($name) ?>"
               autocomplete="<?= $this->e($autocomplete) ?>">
        <?php if ($emailVm->hasError($name)): ?>
        <span class="invalid-feedback d-block"><?= $this->e($emailVm->error($name)) ?></span>
        <?php endif ?>
    </div>
    <?php endforeach ?>

    <p class="form-text">The address changes once you open the link sent to it.</p>

    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Change email</button>
    </div>
</form>
