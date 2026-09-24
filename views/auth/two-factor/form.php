<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\TwoFactorViewModel $vm */ ?>
<div id="two-factor">
<form id="two-factor-form" method="post" action="/two-factor" hx-nonce="<?= $this->e($this->cspNonce()) ?>" hx-post="/two-factor" hx-target="#two-factor" hx-swap="outerHTML">
    <?= $this->csrf() ?>
    <input type="hidden" name="code_type" value="<?= $vm->recovery ? 'recovery' : 'code' ?>">

    <?= $this->partial('partials/form_errors', ['errors' => $vm->formErrors()]) ?>

    <div class="mb-3">
        <?php if ($vm->recovery): ?>
        <label for="code" class="form-label">Recovery code</label>
        <input type="text" id="code" class="form-control <?= $vm->hasError('code') ? 'is-invalid' : '' ?>" name="code" autocomplete="off" autocapitalize="none" spellcheck="false" autofocus>
        <?php else: ?>
        <label for="code" class="form-label">Code from your authenticator app</label>
        <input type="text" id="code" class="form-control <?= $vm->hasError('code') ? 'is-invalid' : '' ?>" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <?php endif ?>
        <?php if ($vm->hasError('code')): ?>
        <span id="codeFeedback" class="invalid-feedback"><?= $this->e($vm->error('code')) ?></span>
        <?php endif ?>
    </div>

    <button type="submit" id="two-factor-submit" class="btn btn-primary w-100">Sign in</button>
</form>

<p class="mt-3 mb-0">
    <?php if ($vm->recovery): ?>
    <a href="/two-factor">Use your authenticator app instead</a>
    <?php else: ?>
    <a href="/two-factor?recovery=1">Use a recovery code instead</a>
    <?php endif ?>
</p>

<form method="post" action="/two-factor/cancel" class="mt-2">
    <?= $this->csrf() ?>
    <button type="submit" class="btn btn-link p-0">Sign in as someone else</button>
</form>
</div>
