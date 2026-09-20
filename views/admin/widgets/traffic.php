<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $period */ ?>
<?php /** @var int $total */ ?>
<?php /** @var int $failed */ ?>
<?php /** @var float|null $failureRate */ ?>
<?php /** @var int|null $average */ ?>
<?php /** @var array{points: string, peak: int, unit: string}|null $spark */ ?>
<?php if ($total === 0): ?>
    <?= $this->partial('admin/partials/widget-empty', ['message' => 'No requests in this period.']) ?>
<?php else: ?>
    <?php if ($spark !== null): ?>
        <?php /* The only thing on the dashboard with a shape rather than a
           value. Stretched to the card by preserveAspectRatio, which would
           stretch the stroke with it — non-scaling-stroke, set in the sheet,
           is what keeps the line one width at both ends. */ ?>
        <svg class="widget-spark" viewBox="0 0 100 32" preserveAspectRatio="none"
             role="img"
             aria-label="Requests per <?= $this->e($spark['unit']) ?>, peaking at <?= $this->e(number_format($spark['peak'])) ?>">
            <polyline class="widget-spark-line" points="<?= $this->e($spark['points']) ?>"></polyline>
        </svg>
    <?php endif ?>

    <div class="widget-figures">
        <div>
            <span class="widget-stat<?= $failed > 0 ? ' is-warning' : '' ?>"><?= $this->e((string) $failureRate) ?>%</span>
            <span class="widget-caption"><?= $this->e(number_format($failed)) ?> failed</span>
        </div>
        <div>
            <span class="widget-stat"><?= $this->e((string) $average) ?><small>ms</small></span>
            <span class="widget-caption">average</span>
        </div>
    </div>
<?php endif ?>
