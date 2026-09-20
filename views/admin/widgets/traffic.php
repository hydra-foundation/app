<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $period */ ?>
<?php /** @var int $total */ ?>
<?php /** @var int $failed */ ?>
<?php /** @var float|null $failureRate */ ?>
<?php /** @var int|null $average */ ?>
<?php if ($total === 0): ?>
    <p class="text-body-secondary mb-0">No requests in this period.</p>
<?php else: ?>
    <div class="widget-figures">
        <div>
            <span class="widget-stat"><?= $this->e(number_format($total)) ?></span>
            <span class="widget-caption">requests</span>
        </div>
        <div>
            <span class="widget-stat<?= $failed > 0 ? ' is-warning' : '' ?>"><?= $this->e((string) $failureRate) ?>%</span>
            <span class="widget-caption"><?= $this->e(number_format($failed)) ?> failed</span>
        </div>
        <div>
            <span class="widget-stat"><?= $this->e((string) $average) ?><small>ms</small></span>
            <span class="widget-caption">average</span>
        </div>
    </div>
    <p class="widget-caption mb-0"><?= $this->e($period) ?></p>
<?php endif ?>
