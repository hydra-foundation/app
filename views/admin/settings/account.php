<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var \App\ViewModels\ChangePasswordViewModel $vm */ ?>
<?php /** @var \App\ViewModels\ChangeEmailViewModel $emailVm */ ?>
<?php /** @var \App\ViewModels\AvatarViewModel $avatarVm */ ?>
<?php $nonce = $this->e($this->cspNonce()) ?>
<?= $this->partial('admin/settings/nav', ['current' => 'account']) ?>

<div class="settings-rows" hx-nonce="<?= $nonce ?>">
    <details class="settings-row" name="account"<?= $avatarVm->hasErrors() ? ' open' : '' ?>>
        <summary>
            <span class="settings-row-label">Avatar</span>
            <span class="settings-row-value">
                <?php if ($avatarVm->hasAvatar()): ?>
                    <img src="<?= $this->e((string) $avatarVm->url) ?>" alt="" class="admin-thumb">
                <?php else: ?>
                    <i class="bi bi-<?= $this->e($avatarVm->icon()) ?> admin-thumb-empty" aria-hidden="true"></i>
                <?php endif ?>
            </span>
            <span class="settings-row-action"><span class="when-closed">Change</span><span class="when-open">Cancel</span></span>
        </summary>

        <?php /* Multipart for the browser and for htmx alike: a file travels
           in no other kind of body. */ ?>
        <form id="avatar-form"
              class="settings-row-form"
              method="post"
              action="/admin/settings/account"
              enctype="multipart/form-data"
              hx-nonce="<?= $nonce ?>"
              hx-post="/admin/settings/account"
              hx-encoding="multipart/form-data"
              hx-target="#admin-frame">
            <?= $this->csrf() ?>
            <input type="hidden" name="intent" value="avatar">

            <?= $this->partial('admin/partials/errors', ['errors' => $avatarVm->formErrors()]) ?>

            <div class="mb-3">
                <label class="form-label" for="avatar">New picture</label>
                <input type="file"
                       id="avatar"
                       class="form-control<?= $avatarVm->hasError('avatar') ? ' is-invalid' : '' ?>"
                       name="avatar"
                       accept="<?= $this->e($avatarVm->accept()) ?>">
                <?php if ($avatarVm->hasError('avatar')): ?>
                <span class="invalid-feedback d-block"><?= $this->e($avatarVm->error('avatar')) ?></span>
                <?php else: ?>
                <span class="form-text"><?= $this->e($avatarVm->help()) ?></span>
                <?php endif ?>
            </div>

            <div class="admin-form-actions d-flex gap-2">
                <button class="btn btn-primary" type="submit">Upload</button>
                <?php if ($avatarVm->hasAvatar()): ?>
                <button class="btn btn-outline-danger" type="submit" name="avatar_remove" value="1">Remove</button>
                <?php endif ?>
            </div>
        </form>
    </details>

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
