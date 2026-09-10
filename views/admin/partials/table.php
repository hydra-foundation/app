<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php $columns = $vm->columns() ?>
<?php $criteria = $vm->page->criteria ?>
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
                <?php if ($vm->hasRowActions()): ?>
                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                <?php endif ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vm->page->rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $field): ?>
                        <td><?= $this->e($vm->cell($field, $row)) ?></td>
                    <?php endforeach ?>
                    <?php if ($vm->hasRowActions()): ?>
                        <td class="text-end">
                            <div class="btn-group">
                                <?php if ($vm->showUrl($row) !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $this->e($vm->showUrl($row)) ?>"
                                       hx-get="<?= $this->e($vm->showUrl($row)) ?>"
                                       hx-target="#admin-frame"
                                       hx-push-url="true">View</a>
                                <?php endif ?>
                                <?php if ($vm->editUrl($row) !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $this->e($vm->editUrl($row)) ?>"
                                       hx-get="<?= $this->e($vm->editUrl($row)) ?>"
                                       hx-target="#admin-frame"
                                       hx-push-url="true">Edit</a>
                                <?php endif ?>

                                <?php if ($vm->deleteUrl($row) !== null): ?>
                                    <?php /* A form, not a link: deleting is a POST, and this still
                                       works when htmx is not the one sending it. It is a btn-group
                                       of its own because Bootstrap joins direct children only, and
                                       the form is what sits between the group and the button. */ ?>
                                    <form class="btn-group"
                                          method="post"
                                          action="<?= $this->e($vm->deleteUrl($row)) ?>"
                                          hx-post="<?= $this->e($vm->deleteUrl($row)) ?>"
                                          hx-target="#admin-frame"
                                          hx-confirm="<?= $this->e($vm->deletePrompt()) ?>">
                                        <?= $this->csrf() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif ?>
                            </div>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>

            <?php if ($vm->page->isEmpty()): ?>
                <tr>
                    <td class="text-center text-body-secondary py-4" colspan="<?= count($columns) + ($vm->hasRowActions() ? 1 : 0) ?>">Nothing to show.</td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>
</div>

<?= $this->partial('admin/partials/pagination', ['vm' => $vm]) ?>
