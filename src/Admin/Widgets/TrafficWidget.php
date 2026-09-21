<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;
use Psr\Clock\ClockInterface;

/**
 * Requests over the chosen period: the shape of them, how many failed, and how
 * long the average one took.
 */
final class TrafficWidget implements PeriodAwareInterface
{
    /** Up to two days of requests is worth an hour a point; past that it is noise. */
    private const HOURLY = 2 * 86400;

    /** And past this many days, a point a day is more points than there is line. */
    private const DAILY = 400 * 86400;

    /** A guard on the loop, not a design: no window should reach it. */
    private const POINTS = 1000;

    /**
     * How many buckets an axis label is worth, per unit, smallest first. The
     * card is one column of a dashboard and not a screen, so a tick every
     * bucket is a smear; six labels is about what fits at this width.
     */
    private const TICKS = 6;
    private const STEPS = [
        'hour' => [1, 2, 3, 4, 6, 8, 12, 24],
        'day' => [1, 2, 5, 7, 10, 14, 28, 30, 60, 90],
        'month' => [1, 2, 3, 6, 12],
    ];

    private Window $window;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClockInterface $clock,
    ) {}

    public function withWindow(Window $window): static
    {
        $clone = clone $this;
        $clone->window = $window;

        return $clone;
    }

    public function present(): array
    {
        [$condition, $bindings] = $this->window->condition('created_at');

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed,
                    AVG(duration_ms) AS average
             FROM activity
             WHERE {$condition}",
            $bindings,
        );

        $total = (int) ($row['total'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);

        return [
            'period' => $this->window->label(),
            'total' => $total,
            'failed' => $failed,
            // Shown rather than computed in the template: a rate over no
            // requests is not zero percent, it is nothing to report.
            'failureRate' => $total === 0 ? null : round($failed / $total * 100, 1),
            'average' => $total === 0 ? null : (int) round((float) ($row['average'] ?? 0)),
            'chart' => $total === 0 ? null : $this->chart(),
        ];
    }

    /**
     * The card's picture: up to three plots over one axis.
     *
     * Three plots and not one with three series, because they are measures of
     * different sizes — six hundred requests, two failures and forty-eight
     * milliseconds share no scale, and a second axis fitted to the second
     * measure would invent a relationship between them that the numbers do not
     * have. Stacked and sharing an x axis, each is read against its own
     * ceiling and against the same hours.
     *
     * Failures get a plot only when there are some. An empty band under a busy
     * chart is a line of furniture claiming to be a finding.
     *
     * @return array{plots: list<Plot>, ticks: list<array{at: string, label: string, anchor: string, spare: bool}>, density: string, unit: string}|null
     */
    private function chart(): ?array
    {
        [$buckets, $unit] = $this->buckets();

        if (count($buckets) < 2) {
            return null;
        }

        $plots = [Plot::of('Requests per ' . $unit, 'lead', $this->points($buckets, $unit, 'total'))];

        if (array_sum(array_column($buckets, 'failed')) > 0) {
            $plots[] = Plot::of('Failed', 'fault', $this->points($buckets, $unit, 'failed'), halfway: false);
        }

        $plots[] = Plot::of(
            'Slowest request',
            'slow',
            $this->points($buckets, $unit, 'slowest'),
            'ms',
            halfway: false,
        );

        return [
            'plots' => $plots,
            'ticks' => $this->ticks($buckets, $unit),
            'density' => Plot::density(count($buckets)),
            'unit' => $unit,
        ];
    }

    /**
     * One series out of the buckets, as the points a plot is built from.
     *
     * Every point is titled with the whole bucket and not with its own series,
     * because the hover target is shared: a reader who stops on nine in the
     * morning wants what happened at nine, not one third of it.
     *
     * @param list<array{at: DateTimeImmutable, total: int, failed: int, slowest: int, partial: bool}> $buckets
     * @param 'total'|'failed'|'slowest' $series
     * @return list<array{value: int, partial: bool, title: string}>
     */
    private function points(array $buckets, string $unit, string $series): array
    {
        return array_map(
            fn (array $bucket): array => [
                'value' => $bucket[$series],
                'partial' => $bucket['partial'],
                'title' => $this->title($bucket, $unit),
            ],
            $buckets,
        );
    }

    /**
     * The labels under the axis, each sitting over the middle of the bucket it
     * names, as a percentage of the width.
     *
     * A percentage because the plots above are stretched to the card and the
     * labels are not — they live in a second SVG of their own, drawn at one
     * unit to the pixel, and a percentage is the one coordinate both agree on
     * without either of them knowing how wide the card turned out.
     *
     * The two ends are the exception: they sit on the edges of the plot rather
     * than on the middles of their buckets, and are anchored outwards from
     * there. Centred like the rest they would hang half their width off the
     * card, and pulled back in they would start where their own bar's middle
     * is, which reads as a label for the bar after it.
     *
     * Every other middle label is marked spare. Six labels fit a card the width
     * of half a screen and collide on a card the width of a phone, and how wide
     * the card turned out is the one thing this cannot know: it is a container
     * query away, in the sheet, which drops the spare ones and leaves a run
     * that is still evenly spaced because it is every second one of an evenly
     * spaced run. The ends are never spare.
     *
     * @param list<array{at: DateTimeImmutable, total: int, failed: int, slowest: int, partial: bool}> $buckets
     * @return list<array{at: string, label: string, anchor: string, spare: bool}>
     */
    private function ticks(array $buckets, string $unit): array
    {
        $count = count($buckets);
        $last = $count - 1;
        $step = $this->step($count, $unit);
        $ticks = [];

        for ($index = 0; $index < $count; $index += $step) {
            // The closing label is worth more than the one before it, and two
            // labels a part-step apart sit on top of each other, so the run
            // stops a whole step short and the end is added on its own below.
            if ($index > $last - $step) {
                break;
            }

            $ticks[] = [
                'at' => $index === 0 ? '0%' : $this->at($index, $count),
                'label' => $this->tick($buckets[$index]['at'], $unit),
                'anchor' => $index === 0 ? 'start' : 'middle',
                'spare' => count($ticks) % 2 === 1,
            ];
        }

        $ticks[] = [
            'at' => '100%',
            // An open window ends wherever the reader is standing, and that is
            // a truer name for the right edge than the hour it happens to be.
            'label' => $this->window->until === null ? 'now' : $this->tick($buckets[$last]['at'], $unit),
            'anchor' => 'end',
            'spare' => false,
        ];

        return $ticks;
    }

    /** How many buckets apart the labels sit: the first step that thins them enough. */
    private function step(int $count, string $unit): int
    {
        foreach (self::STEPS[$unit] as $step) {
            if ((int) ceil($count / $step) <= self::TICKS) {
                return $step;
            }
        }

        return (int) ceil($count / self::TICKS);
    }

    /** The middle of a bucket, across the width of the plot. */
    private function at(int $index, int $count): string
    {
        return round(($index + 0.5) / $count * 100, 2) . '%';
    }

    /**
     * Requests, failures and the slowest one per bucket across the window,
     * zeroes included, and the word for what a bucket is.
     *
     * Grouped on a prefix of the stored timestamp, which both dialects will cut
     * out of a datetime without being told how, and walked in UTC because that
     * is what the column holds: a bucket is a range of instants either way, and
     * lining the walk up with the storage is what keeps every row in exactly
     * one of them.
     *
     * The slowest request and not the average one, because on a site this size
     * the average is one number for a hundred identical fragment fetches and
     * hides the single request that took a second. The average is already on
     * the card, as a figure, where an average belongs.
     *
     * @return array{0: list<array{at: DateTimeImmutable, total: int, failed: int, slowest: int, partial: bool}>, 1: string}
     */
    private function buckets(): array
    {
        $utc = new DateTimeZone('UTC');
        $until = ($this->window->until ?? $this->clock->now())->setTimezone($utc);
        $since = ($this->window->since ?? $this->earliest())?->setTimezone($utc);

        if ($since === null || $since >= $until) {
            return [[], 'hour'];
        }

        $span = $until->getTimestamp() - $since->getTimestamp();

        [$unit, $width, $format] = match (true) {
            $span <= self::HOURLY => ['hour', 13, 'Y-m-d H'],
            $span <= self::DAILY => ['day', 10, 'Y-m-d'],
            default => ['month', 7, 'Y-m'],
        };

        [$condition, $bindings] = $this->window->condition('created_at');

        $counted = [];

        foreach (
            $this->db->select(
                "SELECT SUBSTR(created_at, 1, {$width}) AS bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed,
                        MAX(duration_ms) AS slowest
                 FROM activity
                 WHERE {$condition}
                 GROUP BY bucket",
                $bindings,
            ) as $row
        ) {
            $counted[(string) $row['bucket']] = $row;
        }

        // The walk starts on a bucket boundary, not at the window's edge: a
        // window opening at 09:40 shares its first hour with rows before it,
        // and a cursor started at 09:40 would look for a bucket named 09:40 and
        // find none of them.
        $cursor = match ($unit) {
            'hour' => $since->setTime((int) $since->format('G'), 0),
            'day' => $since->setTime(0, 0),
            default => $since->modify('first day of this month')->setTime(0, 0),
        };

        $series = [];

        while ($cursor < $until && count($series) < self::POINTS) {
            $next = $cursor->modify('+1 ' . $unit);
            $row = $counted[$cursor->format($format)] ?? [];

            $series[] = [
                'at' => $cursor,
                'total' => (int) ($row['total'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'slowest' => (int) ($row['slowest'] ?? 0),
                // A bucket the window opens or closes partway through is short
                // for that reason alone, and saying so is the difference
                // between a quiet hour and an hour that is ten minutes old.
                'partial' => $cursor < $since || $next > $until,
            ];

            $cursor = $next;
        }

        return [$series, $unit];
    }

    /**
     * What a bucket is called on the axis: its own boundary, named in the zone
     * that boundary belongs to.
     *
     * An hour bucket is a UTC hour, and every whole-hour zone puts that on one
     * of the reader's own hours, so it is shown as one — an axis reading 06:00
     * for the reader's midnight is the whole confusion this label exists to
     * settle. A day or a month bucket is a UTC day or month and nothing else;
     * moved into a zone behind UTC it would be named for the day before, so it
     * keeps the name it was counted under.
     */
    private function tick(DateTimeImmutable $at, string $unit): string
    {
        return match ($unit) {
            'hour' => $at->setTimezone($this->zone())->format('H:i'),
            'day' => $at->format('j M'),
            default => $at->format('M Y'),
        };
    }

    /**
     * The hover text on one bucket, which reports all three plots at once: the
     * cursor is over an hour, not over a series.
     *
     * @param array{at: DateTimeImmutable, total: int, failed: int, slowest: int, partial: bool} $bucket
     */
    private function title(array $bucket, string $unit): string
    {
        $when = $this->tick($bucket['at'], $unit);

        if ($unit === 'hour') {
            $when .= '–' . $this->tick($bucket['at']->modify('+1 hour'), $unit);
        }

        $said = [number_format($bucket['total']) . ($bucket['total'] === 1 ? ' request' : ' requests')];

        if ($bucket['failed'] > 0) {
            $said[] = number_format($bucket['failed']) . ' failed';
        }

        if ($bucket['slowest'] > 0) {
            $said[] = $bucket['slowest'] . 'ms slowest';
        }

        if ($bucket['partial']) {
            $said[] = 'partial';
        }

        return $when . ' · ' . implode(' · ', $said);
    }

    /**
     * The reader's zone, which the window is already resolved in. Read off the
     * window rather than asked for again, so the axis cannot name a zone the
     * counting underneath it did not use.
     */
    private function zone(): DateTimeZone
    {
        return ($this->window->now ?? $this->window->since)?->getTimezone() ?? new DateTimeZone('UTC');
    }

    /**
     * Where the records start, for the one period with no lower bound of its
     * own. Paid for only on "all time", and only to decide how wide a bucket is.
     */
    private function earliest(): ?DateTimeImmutable
    {
        $first = $this->db->selectOne('SELECT MIN(created_at) AS first FROM activity')['first'] ?? null;

        return is_string($first) ? new DateTimeImmutable($first, new DateTimeZone('UTC')) : null;
    }
}
