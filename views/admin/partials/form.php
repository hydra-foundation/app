<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\FormViewModel $vm */ ?>
<?php /* Posts at frame depth: the Renderer picks its depth from the htmx target,
   and a submit can come back as this form, or as the list once it saved. Both
   carry their own heading and notice, so the frame is the level that fits. */ ?>
<form id="admin-form"
      class="col-12 col-xl-6"
      method="post"
      action="<?= $this->e($vm->action()) ?>"
      hx-post="<?= $this->e($vm->action()) ?>"
      hx-target="#admin-frame"
      hx-swap="innerHTML">
    <?= $this->csrf() ?>

    <?= $this->partial('partials/form_errors', ['errors' => $vm->formErrors()]) ?>

    <?php foreach ($vm->controls() as $control): ?>
        <?php $id = 'field-' . $control->name() ?>
        <?php $invalid = $vm->hasError($control->name()) ? ' is-invalid' : '' ?>
        <div class="mb-3">
            <label for="<?= $this->e($id) ?>" class="form-label">
                <?= $this->e($control->heading()) ?>
                <?php if ($control->isRequired()): ?>
                    <span class="text-danger" aria-hidden="true">*</span>
                <?php endif ?>
            </label>

            <?php if ($control->type() === \Hydra\Admin\InputType::Select): ?>
                <select id="<?= $this->e($id) ?>"
                        name="<?= $this->e($control->name()) ?>"
                        class="form-select<?= $invalid ?>"
                        <?= $control->isReadonly() ? 'disabled' : '' ?>>
                    <?php foreach ($control->options() ?? [] as $value => $label): ?>
                        <option value="<?= $this->e($value) ?>"<?= $vm->value($control) === (string) $value ? ' selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php elseif ($control->type() === \Hydra\Admin\InputType::Textarea): ?>
                <textarea id="<?= $this->e($id) ?>"
                          name="<?= $this->e($control->name()) ?>"
                          class="form-control<?= $invalid ?>"
                          rows="4"
                          <?= $control->isReadonly() ? 'readonly' : '' ?>><?= $this->e($vm->value($control)) ?></textarea>
            <?php else: ?>
                <input type="<?= $this->e($control->type()->value) ?>"
                       id="<?= $this->e($id) ?>"
                       name="<?= $this->e($control->name()) ?>"
                       class="form-control<?= $invalid ?>"
                       value="<?= $this->e($vm->value($control)) ?>"
                       autocomplete="off"
                       <?= $control->isReadonly() ? 'readonly' : '' ?>>
            <?php endif ?>

            <?php if ($vm->hasError($control->name())): ?>
                <span class="invalid-feedback d-block"><?= $this->e($vm->error($control->name())) ?></span>
            <?php elseif ($control->hint() !== null): ?>
                <span class="form-text"><?= $this->e($control->hint()) ?></span>
            <?php endif ?>
        </div>
    <?php endforeach ?>

    <div class="d-flex gap-2">
        <button type="submit" name="_action" value="save" class="btn btn-primary">Save</button>
        <?php if ($vm->canApply()): ?>
            <button type="submit" name="_action" value="apply" class="btn btn-outline-primary">Apply</button>
        <?php endif ?>
        <a class="btn btn-outline-secondary"
           href="<?= $this->e($vm->cancelUrl()) ?>"
           hx-get="<?= $this->e($vm->cancelUrl()) ?>"
           hx-target="#admin-frame"
           hx-push-url="true">Cancel</a>
    </div>
</form>
