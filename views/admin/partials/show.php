<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ShowViewModel $vm */ ?>
<dl class="row col-12 col-xl-8">
    <?php foreach ($vm->fields() as $field): ?>
        <dt class="col-sm-3 text-body-secondary fw-normal"><?= $this->e($field->heading()) ?></dt>
        <dd class="col-sm-9 text-break"><?= $this->e($vm->value($field)) ?></dd>
    <?php endforeach ?>
</dl>

<div class="d-flex gap-2">
    <?php if ($vm->editUrl() !== null): ?>
        <a class="btn btn-primary"
           href="<?= $this->e($vm->editUrl()) ?>"
           hx-get="<?= $this->e($vm->editUrl()) ?>"
           hx-target="#admin-frame"
           hx-push-url="true">Edit</a>
    <?php endif ?>

    <a class="btn btn-outline-secondary"
       href="<?= $this->e($vm->listUrl()) ?>"
       hx-get="<?= $this->e($vm->listUrl()) ?>"
       hx-target="#admin-frame"
       hx-push-url="true">Back</a>

    <?php if ($vm->deleteUrl() !== null): ?>
        <form class="ms-auto"
              method="post"
              action="<?= $this->e($vm->deleteUrl()) ?>"
              hx-post="<?= $this->e($vm->deleteUrl()) ?>"
              hx-target="#admin-frame"
              hx-confirm="<?= $this->e($vm->deletePrompt()) ?>">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn-outline-danger">Delete</button>
        </form>
    <?php endif ?>
</div>
