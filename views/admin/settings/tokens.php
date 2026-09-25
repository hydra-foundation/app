<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\ViewModels\ApiTokensViewModel $vm */ ?>
<?php $nonce = $this->e($this->cspNonce()) ?>
<?= $this->partial('admin/settings/nav', ['current' => 'tokens']) ?>

<?php if ($vm->plain !== null): ?>
<section class="settings-panel" aria-labelledby="new-token-title">
    <h2 id="new-token-title" class="h5">Your new token: <?= $this->e((string) $vm->plainName) ?></h2>
    <p>Send it as <code>Authorization: Bearer …</code> to anything under <code>/api/</code>. Copy it now: this is the only time it is shown.</p>
    <p><code class="api-token-plain"><?= $this->e($vm->plain) ?></code></p>
</section>
<?php endif ?>

<div class="settings-rows" hx-nonce="<?= $nonce ?>">
    <details class="settings-row" name="tokens"<?= $vm->hasErrors() ? ' open' : '' ?>>
        <summary>
            <span class="settings-row-label">New token</span>
            <span class="settings-row-value"><small>A token signs in as you on the API, and nowhere else.</small></span>
            <span class="settings-row-action"><span class="when-closed">Create</span><span class="when-open">Cancel</span></span>
        </summary>

        <form id="create-form"
              class="settings-row-form"
              method="post"
              action="/admin/settings/tokens"
              hx-nonce="<?= $nonce ?>"
              hx-post="/admin/settings/tokens"
              hx-target="#admin-frame">
            <?= $this->csrf() ?>
            <input type="hidden" name="intent" value="create">
            <div class="mb-3">
                <label class="form-label" for="token_name">Name</label>
                <input type="text"
                       id="token_name"
                       class="form-control<?= $vm->hasError('label') ? ' is-invalid' : '' ?>"
                       name="label"
                       maxlength="100"
                       value="<?= $this->e($vm->old('label')) ?>">
                <?php if ($vm->hasError('label')): ?>
                <span class="invalid-feedback d-block"><?= $this->e($vm->error('label')) ?></span>
                <?php endif ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="token_expires">Expires</label>
                <select id="token_expires"
                        class="form-select<?= $vm->hasError('expires') ? ' is-invalid' : '' ?>"
                        name="expires">
                    <?php foreach ($vm::EXPIRIES as $value => $label): ?>
                    <option value="<?= $value ?>"<?= $vm->old('expires', '90') === (string) $value ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if ($vm->hasError('expires')): ?>
                <span class="invalid-feedback d-block"><?= $this->e($vm->error('expires')) ?></span>
                <?php endif ?>
            </div>
            <div class="admin-form-actions">
                <button class="btn btn-primary" type="submit">Create token</button>
            </div>
        </form>
    </details>

    <?php if ($vm->tokens === []): ?>
    <div class="settings-row">
        <div class="settings-row-static">
            <span class="settings-row-label">Tokens</span>
            <span class="settings-row-value"><small>No tokens yet.</small></span>
        </div>
    </div>
    <?php endif ?>

    <?php foreach ($vm->tokens as $token): ?>
    <div class="settings-row">
        <div class="settings-row-static">
            <span class="settings-row-label"><?= $this->e($token['name']) ?></span>
            <span class="settings-row-value">Created <?= $this->e($token['created']) ?><small>Last used <?= $this->e($token['used'] ?? 'never') ?> · <?= $token['expires'] === null ? 'Never expires' : 'Expires ' . $this->e($token['expires']) ?></small></span>
            <form method="post"
                  action="/admin/settings/tokens"
                  hx-nonce="<?= $nonce ?>"
                  hx-post="/admin/settings/tokens"
                  hx-target="#admin-frame">
                <?= $this->csrf() ?>
                <input type="hidden" name="intent" value="revoke">
                <input type="hidden" name="token" value="<?= $this->e((string) $token['id']) ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
            </form>
        </div>
    </div>
    <?php endforeach ?>
</div>
