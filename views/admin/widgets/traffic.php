<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $period */ ?>
<?php /** @var int $total */ ?>
<?php /** @var int $failed */ ?>
<?php /** @var float|null $failureRate */ ?>
<?php /** @var int|null $average */ ?>
<?php /** @var array{bars: list<array{x: float, y: float, width: float, height: float, title: string, partial: bool}>, peak: int, unit: string, first: string, last: string}|null $spark */ ?>
<?php if ($total === 0): ?>
    <?= $this->partial('admin/partials/widget-empty', ['message' => 'No requests in this period.']) ?>
<?php else: ?>
    <?php if ($spark !== null): ?>
        <?php /* The only thing on the dashboard with a shape rather than a
           value — which is exactly why it needs the two figures around it. The
           plot is stretched to the card by preserveAspectRatio, so every word
           on the chart sits outside the SVG: text inside one would be stretched
           with it, and the peak and the two ends are the whole scale. */ ?>
        <figure class="widget-chart">
            <p class="widget-chart-scale">
                Requests per <?= $this->e($spark['unit']) ?>, peaking at
                <span class="widget-chart-peak"><?= $this->e(number_format($spark['peak'])) ?></span>
            </p>
            <?php /* Hidden from assistive technology rather than labelled: the
               caption above and the axis below already say in text what an
               aria-label would repeat. */ ?>
            <svg class="widget-spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true">
                <line class="widget-spark-grid" x1="0" y1="1" x2="100" y2="1"></line>
                <?php foreach ($spark['bars'] as $bar): ?>
                    <rect class="widget-spark-bar<?= $bar['partial'] ? ' is-partial' : '' ?>"
                          x="<?= $this->e((string) $bar['x']) ?>"
                          y="<?= $this->e((string) $bar['y']) ?>"
                          width="<?= $this->e((string) $bar['width']) ?>"
                          height="<?= $this->e((string) $bar['height']) ?>">
                        <title><?= $this->e($bar['title']) ?></title>
                    </rect>
                <?php endforeach ?>
                <line class="widget-spark-base" x1="0" y1="31" x2="100" y2="31"></line>
            </svg>
            <figcaption class="widget-chart-axis">
                <span><?= $this->e($spark['first']) ?></span>
                <span><?= $this->e($spark['last']) ?></span>
            </figcaption>
        </figure>
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
