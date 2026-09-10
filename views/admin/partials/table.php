<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php $columns = $vm->columns() ?>
<?php $criteria = $vm->page->criteria ?>
<?php /* The list's sort state, kept inside the swapped region so it is always as
       fresh as the table it describes. The filter toolbar renders outside that
       region and reaches in for it with hx-include. */ ?>
<div id="admin-sort-state">
    <?php if ($criteria->sort !== null): ?>
        <input type="hidden" name="sort" value="<?= $this->e($criteria->sort) ?>">
        <input type="hidden" name="dir" value="<?= $this->e($criteria->direction) ?>">
    <?php endif ?>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-3">
        <thead>
            <tr>
                <?php foreach ($columns as $field): ?>
                    <th scope="col">
                        <?php if ($field->isSortable()): ?>
                            <a class="text-decoration-none text-body-emphasis"
                               href="<?= $this->e($vm->sortLink($field)) ?>"
                               hx-get="<?= $this->e($vm->sortLink($field)) ?>"
                               hx-target="#admin-body"
                               hx-push-url="true">
                                <?= $this->e($field->heading()) ?>
                                <?php if ($vm->sortedBy($field) !== null): ?>
                                    <span aria-hidden="true"><?= $vm->sortedBy($field) === 'asc' ? '&uarr;' : '&darr;' ?></span>
                                <?php endif ?>
                            </a>
                        <?php else: ?>
                            <?= $this->e($field->heading()) ?>
                        <?php endif ?>
                    </th>
                <?php endforeach ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vm->page->rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $field): ?>
                        <td><?= $this->e($vm->cell($field, $row)) ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>

            <?php if ($vm->page->isEmpty()): ?>
                <tr>
                    <td class="text-center text-body-secondary py-4" colspan="<?= count($columns) ?>">Nothing to show.</td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>
</div>

<?= $this->partial('admin/partials/pagination', ['vm' => $vm]) ?>
