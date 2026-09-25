<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var \App\ViewModels\SecurityViewModel $vm */ ?>
<?php $nonce = $this->e($this->cspNonce()) ?>
<?= $this->partial('admin/settings/nav', ['current' => 'security']) ?>

<?php if ($vm->codes !== null): ?>
<section class="settings-panel" aria-labelledby="recovery-codes-title">
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
<div class="settings-rows" hx-nonce="<?= $nonce ?>">
    <?php foreach (['disable' => ['Two-factor', 'On', 'Since ' . $vm->enabledAt, 'Turn off', 'Your password alone will sign you in.', 'Turn off two-factor sign in', 'btn-outline-danger'], 'regenerate' => ['Recovery codes', $vm->remaining . ' left', 'Each signs you in once if you lose your phone.', 'Replace', 'Replaces every recovery code, used or not.', 'Replace recovery codes', 'btn-primary']] as $form => [$label, $value, $note, $action, $lead, $submit, $button]): ?>
    <details class="settings-row<?= $form === 'disable' ? ' is-danger' : '' ?>" name="security"<?= $vm->form === $form && $vm->hasErrors() ? ' open' : '' ?>>
        <summary>
            <span class="settings-row-label"><?= $this->e($label) ?></span>
            <span class="settings-row-value"><?php if ($form === 'disable'): ?><span class="settings-state is-on"><?= $this->e($value) ?></span><?php else: ?><?= $this->e($value) ?><?php endif ?><small><?= $this->e($note) ?></small></span>
            <span class="settings-row-action"><span class="when-closed"><?= $this->e($action) ?></span><span class="when-open">Cancel</span></span>
        </summary>

        <form id="<?= $form ?>-form"
              class="settings-row-form"
              method="post"
              action="/admin/settings/security"
              hx-nonce="<?= $nonce ?>"
              hx-post="/admin/settings/security"
              hx-target="#admin-frame">
            <?= $this->csrf() ?>
            <input type="hidden" name="intent" value="<?= $form ?>">
            <?= $this->partial('admin/settings/security_proof', ['vm' => $vm, 'form' => $form, 'codeLabel' => 'Code from your app, or a recovery code']) ?>
            <p class="form-text"><?= $this->e($lead) ?></p>
            <div class="admin-form-actions">
                <button class="btn <?= $button ?>" type="submit"><?= $this->e($submit) ?></button>
            </div>
        </form>
    </details>
    <?php endforeach ?>
</div>

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
<div class="settings-rows" hx-nonce="<?= $nonce ?>">
    <div class="settings-row">
        <div class="settings-row-static">
            <span class="settings-row-label">Two-factor</span>
            <span class="settings-row-value"><span class="settings-state">Off</span><small>With it on, signing in also takes a code from an authenticator app on your phone.</small></span>
            <form id="start-form"
                  method="post"
                  action="/admin/settings/security"
                  hx-nonce="<?= $nonce ?>"
                  hx-post="/admin/settings/security"
                  hx-target="#admin-frame">
                <?= $this->csrf() ?>
                <input type="hidden" name="intent" value="start">
                <button class="btn btn-sm btn-outline-primary" type="submit">Set up</button>
            </form>
        </div>
    </div>
</div>
<?php endif ?>
