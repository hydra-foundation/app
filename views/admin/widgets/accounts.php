<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var int $total */ ?>
<?php /** @var list<array{label: string, value: string, count: int}> $byRole */ ?>
<p class="widget-stat"><?= $this->e((string) $total) ?> <small class="widget-caption">new</small></p>

<ul class="widget-rows">
    <?php foreach ($byRole as $role): ?>
        <li>
            <?php /* Straight to the role's filter link on the users list, which
               is the question the number provokes. */ ?>
            <a href="/admin/users?view=<?= $this->e($role['value']) ?>"
               hx-nonce="<?= $this->e($this->cspNonce()) ?>"
               hx-get="/admin/users?view=<?= $this->e($role['value']) ?>"
               hx-target="#admin-frame"
               hx-push-url="true"><?= $this->e($role['label']) ?></a>
            <span class="widget-figure"><?= $this->e((string) $role['count']) ?></span>
        </li>
    <?php endforeach ?>
</ul>
