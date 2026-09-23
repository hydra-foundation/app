<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\ForgotPasswordViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Forgot password · Hydra<?php $this->stop() ?>

<div class="auth">
    <div class="auth-inner">
        <div class="auth-panel">
            <h1 class="auth-title">Forgot password</h1>
            <?php if ($vm->status !== null): ?>
                <div class="alert alert-success" role="status"><?= $this->e($vm->status) ?></div>
            <?php else: ?>
            <form id="forgot-form" method="post" action="/forgot-password">
                <?= $this->csrf() ?>

                <?= $this->partial('partials/form_errors', ['errors' => $vm->formErrors()]) ?>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" class="form-control <?=($vm->hasError('email') ? 'is-invalid' : '')?>" name="email" value="<?= $this->e($vm->email) ?>" autocomplete="email" autofocus>
                    <?php if ($vm->hasError('email')): ?>
                    <span id="emailFeedback" class="invalid-feedback"><?= $this->e($vm->error('email')) ?></span>
                    <?php endif ?>
                </div>

                <button type="submit" id="forgot-submit" class="btn btn-primary w-100">Send reset link</button>
            </form>
            <?php endif ?>
            <p class="mt-3 mb-0"><a href="/login">Back to sign in</a></p>
        </div>
    </div>
</div>
