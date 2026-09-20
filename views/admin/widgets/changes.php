<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array<string, mixed>> $changes */ ?>
<?php if ($changes === []): ?>
    <p class="text-body-secondary mb-0">Nothing was changed in this period.</p>
<?php else: ?>
    <ul class="widget-rows">
        <?php foreach ($changes as $change): ?>
            <li>
                <a href="/admin/audit/<?= $this->e((string) $change['id']) ?>"
                   hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                   hx-get="/admin/audit/<?= $this->e((string) $change['id']) ?>"
                   hx-target="#admin-frame"
                   hx-push-url="true"><?= $this->e($change['message']) ?></a>
                <span class="widget-caption"><?= $this->e($change['username'] ?? 'system') ?> &middot; <?= $this->e($change['created_at']) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
