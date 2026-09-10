<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<div class="d-flex justify-content-between align-items-end gap-3 mb-3">
<form class="row g-2 align-items-end"
      hx-get="<?= $this->e($vm->url()) ?>"
      hx-target="#admin-body"
      hx-include="#admin-sort-state"
      hx-push-url="true"
      hx-trigger="submit, change, keyup changed delay:300ms">
    <?php if ($vm->isSearchable()): ?>
        <div class="col-auto">
            <label class="form-label" for="admin-search">Search</label>
            <input class="form-control" id="admin-search" type="search" name="q"
                   value="<?= $this->e($vm->search()) ?>" autocomplete="off">
        </div>
    <?php endif ?>

    <?php foreach ($vm->filters() as $field): ?>
        <div class="col-auto">
            <label class="form-label" for="admin-filter-<?= $this->e($field->name()) ?>"><?= $this->e($field->heading()) ?></label>
            <select class="form-select" id="admin-filter-<?= $this->e($field->name()) ?>" name="<?= $this->e($field->name()) ?>">
                <option value="">All</option>
                <?php foreach ($field->options() ?? [] as $value => $label): ?>
                    <option value="<?= $this->e($value) ?>"<?= $vm->filterValue($field) === (string) $value ? ' selected' : '' ?>>
                        <?= $this->e($label) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    <?php endforeach ?>
</form>

    <?php if ($vm->createUrl() !== null): ?>
        <a class="btn btn-primary text-nowrap"
           href="<?= $this->e($vm->createUrl()) ?>"
           hx-get="<?= $this->e($vm->createUrl()) ?>"
           hx-target="#admin-frame"
           hx-push-url="true"><?= $this->e($vm->createLabel()) ?></a>
    <?php endif ?>
</div>
