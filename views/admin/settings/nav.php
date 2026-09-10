<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $current */ ?>
<?php /* Each category is a URL, so these are links and the browser keeps the
   history. They swap the frame like every other admin link. */ ?>
<nav class="settings-nav" aria-label="Settings categories">
    <?php foreach (['' => 'General', 'appearance' => 'Appearance'] as $path => $label): ?>
        <?php $url = rtrim('/admin/settings/' . $path, '/') ?>
        <a class="settings-tab<?= $path === $current ? ' active' : '' ?>"
           href="<?= $this->e($url) ?>"
           hx-get="<?= $this->e($url) ?>"
           hx-target="#admin-frame"
           hx-push-url="true"
           <?= $path === $current ? 'aria-current="page"' : '' ?>><?= $this->e($label) ?></a>
    <?php endforeach ?>
</nav>
