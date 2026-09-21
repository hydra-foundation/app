<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $period */ ?>
<?php /** @var int $total */ ?>
<?php /** @var int $failed */ ?>
<?php /** @var float|null $failureRate */ ?>
<?php /** @var int|null $average */ ?>
<?php /** @var array{plots: list<\App\Admin\Widgets\Plot>, ticks: list<array{at: string, label: string, anchor: string, spare: bool}>, density: string, unit: string}|null $chart */ ?>
<?php if ($total === 0): ?>
    <?= $this->partial('admin/partials/widget-empty', ['message' => 'No requests in this period.']) ?>
<?php else: ?>
    <?php if ($chart !== null): ?>
        <?php /* Two coordinate systems, because one cannot do both jobs. The
           plots are stretched to whatever width the card ended up with, which
           is what preserveAspectRatio="none" buys and what would smear any
           lettering inside them. So every word sits in its own SVG below,
           drawn at one unit to the pixel, and the two are tied together by
           percentages — the only coordinate that means the same thing in a
           stretched box and an unstretched one. */ ?>
        <figure class="widget-chart <?= $this->e($chart['density']) ?>">
            <?php foreach ($chart['plots'] as $plot): ?>
                <p class="widget-plot-label"><?= $this->e($plot->label) ?></p>
                <div class="widget-plot-scale">
                    <span><?= $this->e($plot->top) ?></span>
                    <?php if ($plot->middle !== null): ?>
                        <span><?= $this->e($plot->middle) ?></span>
                    <?php endif ?>
                    <span>0</span>
                </div>
                <?php /* The bands are decoration over a figure that already
                   says what they show; the lead plot is not, because the hover
                   targets live in it and an aria-hidden subtree is a poor place
                   to put the only thing on the card you can interact with. */ ?>
                <svg class="widget-plot is-<?= $this->e($plot->tone) ?>"
                     viewBox="0 0 100 32" preserveAspectRatio="none"
                     <?= $plot->tone === 'lead' ? '' : 'aria-hidden="true"' ?>>
                    <line class="widget-plot-grid" x1="0" y1="1" x2="100" y2="1"></line>
                    <?php if ($plot->middle !== null): ?>
                        <line class="widget-plot-grid" x1="0" y1="16" x2="100" y2="16"></line>
                    <?php endif ?>
                    <?php foreach ($plot->bars as $bar): ?>
                        <line class="widget-plot-bar<?= $bar['partial'] ? ' is-partial' : '' ?>"
                              x1="<?= $this->e((string) $bar['x']) ?>" y1="31"
                              x2="<?= $this->e((string) $bar['x']) ?>"
                              y2="<?= $this->e((string) $bar['top']) ?>"></line>
                    <?php endforeach ?>
                    <line class="widget-plot-base" x1="0" y1="31" x2="100" y2="31"></line>
                    <?php /* Last, so it sits over the bars, and only on the
                       lead plot: one bucket has one story and the title on
                       these carries all three plots' worth of it. */ ?>
                    <?php if ($plot->tone === 'lead'): ?>
                        <?php foreach ($plot->hits as $hit): ?>
                            <rect class="widget-plot-hit"
                                  x="<?= $this->e((string) $hit['x']) ?>" y="0"
                                  width="<?= $this->e((string) $hit['width']) ?>" height="32">
                                <title><?= $this->e($hit['title']) ?></title>
                            </rect>
                        <?php endforeach ?>
                    <?php endif ?>
                </svg>
            <?php endforeach ?>

            <span class="widget-chart-gutter"></span>
            <svg class="widget-chart-axis" aria-hidden="true">
                <?php foreach ($chart['ticks'] as $tick): ?>
                    <text class="<?= $tick['spare'] ? 'is-spare' : '' ?>"
                          x="<?= $this->e($tick['at']) ?>" y="10"
                          text-anchor="<?= $this->e($tick['anchor']) ?>"><?= $this->e($tick['label']) ?></text>
                <?php endforeach ?>
            </svg>

            <?php /* The plots are hidden from assistive technology and said in
               words here instead: a screen reader gets the shape as a sentence,
               which is more than it could make of four hundred rectangles. */ ?>
            <figcaption class="visually-hidden">
                <?= $this->e($chart['plots'][0]->label) ?>, over
                <?= $this->e(strtolower($period)) ?>, peaking at
                <?= $this->e(number_format($chart['plots'][0]->ceiling)) ?>.
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
