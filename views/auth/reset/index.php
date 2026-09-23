<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\ResetPasswordViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Reset password · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Choose a new password</h1>
            <form id="reset-form" method="post" action="/reset-password">
                <?= $this->csrf() ?>

                <?= $this->partial('partials/form_errors', ['errors' => $vm->formErrors()]) ?>

                <input type="text" name="username" value="<?= $this->e($vm->username) ?>" autocomplete="username" hidden>

                <div class="mb-3">
                    <label for="password" class="form-label">New password</label>
                    <input type="password" id="password" class="form-control <?=($vm->hasError('password') ? 'is-invalid' : '')?>" name="password" autocomplete="new-password" autofocus>
                    <?php if ($vm->hasError('password')): ?>
                    <span id="passwordFeedback" class="invalid-feedback"><?= $this->e($vm->error('password')) ?></span>
                    <?php endif ?>
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm new password</label>
                    <input type="password" id="password_confirmation" class="form-control" name="password_confirmation" autocomplete="new-password">
                </div>

                <button type="submit" id="reset-submit" class="btn btn-primary w-100">Reset password</button>
            </form>
        </div>
    </div>
</div>
