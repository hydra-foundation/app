<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array<string, mixed>> $accounts */ ?>
<?php if ($accounts === []): ?>
    <p class="text-body-secondary mb-0">No accounts in this period.</p>
<?php else: ?>
    <ul class="widget-rows">
        <?php foreach ($accounts as $account): ?>
            <li>
                <a href="/admin/users/<?= $this->e((string) $account['id']) ?>"
                   hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                   hx-get="/admin/users/<?= $this->e((string) $account['id']) ?>"
                   hx-target="#admin-frame"
                   hx-push-url="true"><?= $this->e($account['username']) ?></a>
                <span class="widget-caption"><?= $this->e($account['role']) ?> &middot; <?= $this->e($account['created_at']) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
