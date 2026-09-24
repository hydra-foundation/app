<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\ViewModels\SecurityViewModel $vm */ ?>
<?php /** @var string $form */ ?>
<?php /** @var string $codeLabel */ ?>
<?php foreach (['code' => [$codeLabel, 'text', 'one-time-code'], 'current_password' => ['Current password', 'password', 'current-password']] as $name => [$label, $type, $autocomplete]): ?>
<?php $id = $form . '_' . $name ?>
<?php $error = $vm->errorIn($form, $name) ?>
<div class="mb-3">
    <label class="form-label" for="<?= $this->e($id) ?>"><?= $this->e($label) ?></label>
    <input type="<?= $this->e($type) ?>"
           id="<?= $this->e($id) ?>"
           class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>"
           name="<?= $this->e($name) ?>"
           autocomplete="<?= $this->e($autocomplete) ?>">
    <?php if ($error !== ''): ?>
    <span class="invalid-feedback d-block"><?= $this->e($error) ?></span>
    <?php endif ?>
</div>
<?php endforeach ?>
