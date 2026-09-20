<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $period */ ?>
<?php /** @var list<array{path: string, hits: int, failed: int, average: int, share: int}> $paths */ ?>
<?php if ($paths === []): ?>
    <p class="text-body-secondary mb-0">No requests in this period.</p>
<?php else: ?>
    <ul class="widget-bars">
        <?php foreach ($paths as $row): ?>
            <li>
                <div class="widget-bar-head">
                    <code><?= $this->e($row['path']) ?></code>
                    <span class="widget-figure"><?= $this->e(number_format($row['hits'])) ?></span>
                </div>
                <?php /* The share rides in an attribute rather than a style, so
                   the bar needs no inline CSS. style-src carries no
                   'unsafe-inline' and nonces do not apply to style attributes,
                   so the width this used to set was refused by the policy and
                   every bar drew empty. */ ?>
                <progress class="widget-bar" max="100"
                          value="<?= $this->e((string) $row['share']) ?>"
                          aria-hidden="true"></progress>
                <p class="widget-caption">
                    <?= $this->e((string) $row['average']) ?>ms average<?php if ($row['failed'] > 0): ?>,
                        <span class="is-warning"><?= $this->e((string) $row['failed']) ?> failed</span>
                    <?php endif ?>
                </p>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
