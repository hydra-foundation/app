<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<form id="login-form" method="post" action="/login" hx-post="/login" hx-target="this" hx-swap="outerHTML">
    <?= $this->csrf() ?>

    <?= $this->partial('partials/form_errors', ['errors' => $vm->formErrors()]) ?>

    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" id="username" class="form-control <?=($vm->hasError('username') ? 'is-invalid' : '')?>" name="username" value="<?= $this->e($vm->username) ?>" autocomplete="username"<?= $vm->hasErrors() ? '' : ' autofocus' ?>>
        <?php if ($vm->hasError('username')): ?>
        <span id="usernameFeedback" class="invalid-feedback"><?= $this->e($vm->error('username')) ?></span>
        <?php endif ?>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" id="password" class="form-control <?=($vm->hasError('password') ? 'is-invalid' : '')?>" name="password" autocomplete="current-password">
        <?php if ($vm->hasError('password')): ?>
        <span id="passwordFeedback" class="invalid-feedback"><?= $this->e($vm->error('password')) ?></span>
        <?php endif ?>
    </div>

    <button type="submit" id="login-submit" class="btn btn-primary w-100">Sign in</button>
</form>
