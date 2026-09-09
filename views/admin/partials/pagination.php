<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php $page = $vm->page ?>
<div class="d-flex justify-content-between align-items-center">
    <small class="text-body-secondary">
        <?= $page->isEmpty() ? 'No results' : sprintf('Showing %d–%d of %d', $page->from(), $page->to(), $page->total) ?>
    </small>

    <?php if ($page->pages() > 1): ?>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $page->hasPrevious() ? '' : ' disabled' ?>">
                <a class="page-link" href="<?= $this->e($vm->pageLink($page->criteria->page - 1)) ?>"
                   hx-get="<?= $this->e($vm->pageLink($page->criteria->page - 1)) ?>"
                   hx-target="#admin-body" hx-push-url="true">Previous</a>
            </li>

            <?php foreach ($vm->pageWindow() as $number): ?>
                <li class="page-item<?= $number === $page->criteria->page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= $this->e($vm->pageLink($number)) ?>"
                       hx-get="<?= $this->e($vm->pageLink($number)) ?>"
                       hx-target="#admin-body" hx-push-url="true"><?= $number ?></a>
                </li>
            <?php endforeach ?>

            <li class="page-item<?= $page->hasNext() ? '' : ' disabled' ?>">
                <a class="page-link" href="<?= $this->e($vm->pageLink($page->criteria->page + 1)) ?>"
                   hx-get="<?= $this->e($vm->pageLink($page->criteria->page + 1)) ?>"
                   hx-target="#admin-body" hx-push-url="true">Next</a>
            </li>
        </ul>
    <?php endif ?>
</div>
