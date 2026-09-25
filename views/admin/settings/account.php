<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var \App\ViewModels\ChangePasswordViewModel $vm */ ?>
<?php /** @var \App\ViewModels\ChangeEmailViewModel $emailVm */ ?>
<?php $nonce = $this->e($this->cspNonce()) ?>
<?= $this->partial('admin/settings/nav', ['current' => 'account']) ?>

<div class="settings-rows" hx-nonce="<?= $nonce ?>">
    <div class="settings-row">
        <div class="settings-row-static">
            <span class="settings-row-label">Username</span>
            <span class="settings-row-value"><?= $this->e($user->username) ?></span>
        </div>
    </div>

    <details class="settings-row" name="account"<?= $emailVm->hasErrors() ? ' open' : '' ?>>
        <summary>
            <span class="settings-row-label">Email</span>
            <span class="settings-row-value"><?= $this->e($user->email) ?><small><?= $user->hasVerifiedEmail() ? 'Verified' : 'Not verified' ?></small></span>
            <span class="settings-row-action"><span class="when-closed">Change</span><span class="when-open">Cancel</span></span>
        </summary>

        <form id="email-form"
              class="settings-row-form"
              method="post"
              action="/admin/settings/account"
              hx-nonce="<?= $nonce ?>"
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
    </details>

    <details class="settings-row" name="account"<?= $vm->hasErrors() ? ' open' : '' ?>>
        <summary>
            <span class="settings-row-label">Password</span>
            <span class="settings-row-value" aria-hidden="true">••••••••••</span>
            <span class="settings-row-action"><span class="when-closed">Change</span><span class="when-open">Cancel</span></span>
        </summary>

        <form id="password-form"
              class="settings-row-form"
              method="post"
              action="/admin/settings/account"
              hx-nonce="<?= $nonce ?>"
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
    </details>
</div>
