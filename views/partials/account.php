<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\View\Avatars $avatars */ ?>
<?php /* Who is signed in, for the admin's account slot: the picture (or the
   icon) and the name, both leading to where the picture is changed. Drawn
   twice by the shell, once per width, so it carries no id. */ ?>
<?php $user = $avatars->user() ?>
<?php if ($user !== null): ?>
    <?php $picture = $avatars->current() ?>
    <a href="/admin/settings/account" title="Your account">
        <?php if ($picture !== null): ?>
            <img src="<?= $this->e($picture) ?>" alt="" class="admin-thumb">
        <?php else: ?>
            <i class="bi bi-<?= $this->e($avatars->icon()) ?> admin-thumb-empty" aria-hidden="true"></i>
        <?php endif ?>
        <span><?= $this->e($user->username) ?></span>
    </a>
<?php endif ?>
