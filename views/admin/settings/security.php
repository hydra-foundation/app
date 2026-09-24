<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var \App\ViewModels\SecurityViewModel $vm */ ?>
<?php $nonce = $this->e($this->cspNonce()) ?>
<?= $this->partial('admin/settings/nav', ['current' => 'security']) ?>

<?php if ($vm->codes !== null): ?>
<section class="col-12 col-xl-6 mb-4" aria-labelledby="recovery-codes-title">
    <h2 id="recovery-codes-title" class="h5">Your recovery codes</h2>
    <p>Each one signs you in once if you lose your phone. Keep them somewhere safe: this is the only time they are shown.</p>
    <ol class="recovery-codes">
        <?php foreach ($vm->codes as $code): ?>
        <li><?= $this->e($code) ?></li>
        <?php endforeach ?>
    </ol>
</section>
<?php endif ?>

<?php if ($vm->enabled): ?>
<dl class="admin-show" hx-nonce="<?= $nonce ?>">
    <dt>Two-factor sign in</dt>
    <dd class="type-text">On since <?= $this->e((string) $vm->enabledAt) ?></dd>

    <dt>Recovery codes</dt>
    <dd class="type-text"><?= $vm->remaining ?> left</dd>
</dl>

<?php foreach (['regenerate' => ['New recovery codes', 'Replaces every recovery code, used or not.', 'btn-primary'], 'disable' => ['Turn off two-factor sign in', 'Your password alone will sign you in.', 'btn-outline-danger']] as $form => [$title, $lead, $button]): ?>
<form id="<?= $form ?>-form"
      class="col-12 col-xl-6 mt-4"
      method="post"
      action="/admin/settings/security"
      hx-nonce="<?= $nonce ?>"
      hx-post="/admin/settings/security"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>
    <input type="hidden" name="intent" value="<?= $form ?>">
    <h2 class="h5"><?= $this->e($title) ?></h2>
    <p class="form-text"><?= $this->e($lead) ?></p>
    <?= $this->partial('admin/settings/security_proof', ['vm' => $vm, 'form' => $form, 'codeLabel' => 'Code from your app, or a recovery code']) ?>
    <div class="admin-form-actions">
        <button class="btn <?= $button ?>" type="submit"><?= $this->e($title) ?></button>
    </div>
</form>
<?php endforeach ?>

<?php elseif ($vm->settingUp()): ?>
<form id="confirm-form"
      class="col-12 col-xl-6"
      method="post"
      action="/admin/settings/security"
      hx-nonce="<?= $nonce ?>"
      hx-post="/admin/settings/security"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>
    <input type="hidden" name="intent" value="confirm">
    <p>Scan this with an authenticator app, or type the key into it.</p>
    <div class="two-factor-qr mb-3" data-qr="<?= $this->e((string) $vm->setupUri) ?>" role="img" aria-label="QR code for your authenticator app"></div>
    <p>Key: <code class="two-factor-key"><?= $this->e($vm->groupedSecret()) ?></code></p>
    <?= $this->partial('admin/settings/security_proof', ['vm' => $vm, 'form' => 'confirm', 'codeLabel' => 'Code your app shows']) ?>
    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Turn on</button>
    </div>
</form>

<form class="col-12 col-xl-6 mt-2"
      method="post"
      action="/admin/settings/security"
      hx-nonce="<?= $nonce ?>"
      hx-post="/admin/settings/security"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>
    <input type="hidden" name="intent" value="cancel">
    <button class="btn btn-link p-0" type="submit">Cancel</button>
</form>

<?php else: ?>
<form id="start-form"
      class="col-12 col-xl-6"
      method="post"
      action="/admin/settings/security"
      hx-nonce="<?= $nonce ?>"
      hx-post="/admin/settings/security"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>
    <input type="hidden" name="intent" value="start">
    <p>Two-factor sign in is off. With it on, signing in takes a code from an authenticator app on your phone as well as your password.</p>
    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Set up two-factor sign in</button>
    </div>
</form>
<?php endif ?>
