<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<nav class="admin-sidebar border-end p-3">
    <a class="d-block fw-semibold mb-3 text-decoration-none text-body-emphasis" href="/admin">Hydra</a>

    <?= $this->partial('admin/partials/nav', ['screen' => $screen, 'oob' => false]) ?>

    <form class="mt-4" hx-post="/logout">
        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Sign out</button>
    </form>
</nav>
