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

    /** The plot in user units: a 100 by 32 viewBox with a unit of headroom. */
    private const BASE = 31.0;
    private const SPAN = 30.0;

    /** A quarter of the slot, and never more than this, so 365 bars still fit. */
    private const GAP = 0.25;
    private const GAP_MAX = 0.5;

    /** A bucket with requests in it never draws as a bucket with none. */
    private const STUB = 0.6;

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
            'spark' => $total === 0 ? null : $this->spark(),
        ];
    }

    /**
     * The chart, as the geometry a <rect> takes.
     *
     * Columns and not a line. These are counts in discrete buckets, and a line
     * drawn through them interpolates hours that never happened: an idle
     * afternoon arrives as a cliff, a floor and a recovery rather than as six
     * empty bars, which is a different and much more alarming claim.
     *
     * Drawn here rather than in the template because it is arithmetic, and
     * because the alternative under this content policy is a style attribute:
     * style-src carries no 'unsafe-inline' and a nonce does not reach a style
     * attribute, so anything positioned by CSS from PHP is refused. Geometry is
     * a presentation attribute and arrives intact.
     *
     * @return array{
     *     bars: list<array{x: float, y: float, width: float, height: float, title: string, partial: bool}>,
     *     peak: int,
     *     unit: string,
     *     first: string,
     *     last: string,
     * }|null
     */
    private function spark(): ?array
    {
        [$buckets, $unit] = $this->buckets();

        if (count($buckets) < 2) {
            return null;
        }

        $peak = max(array_column($buckets, 'total'));
        $slot = 100 / count($buckets);
        $gap = min($slot * self::GAP, self::GAP_MAX);
        $bars = [];

        foreach ($buckets as $index => $bucket) {
            $height = $peak === 0 || $bucket['total'] === 0
                ? 0.0
                : max(self::STUB, $bucket['total'] / $peak * self::SPAN);

            $bars[] = [
                'x' => round($index * $slot + $gap / 2, 2),
                'y' => round(self::BASE - $height, 2),
                'width' => round($slot - $gap, 2),
                'height' => round($height, 2),
                'title' => $this->title($bucket, $unit),
                'partial' => $bucket['partial'],
            ];
        }

        return [
            'bars' => $bars,
            'peak' => $peak,
            'unit' => $unit,
            'first' => $this->tick($buckets[0]['at'], $unit),
            // An open window ends wherever the reader is standing, and that is
            // a truer name for the right edge than the hour it happens to be.
            'last' => $this->window->until === null
                ? 'now'
                : $this->tick($buckets[count($buckets) - 1]['at'], $unit),
        ];
    }

    /**
     * Requests per bucket across the window, zeroes included, and the word for
     * what a bucket is.
     *
     * Grouped on a prefix of the stored timestamp, which both dialects will cut
     * out of a datetime without being told how, and walked in UTC because that
     * is what the column holds: a bucket is a range of instants either way, and
     * lining the walk up with the storage is what keeps every row in exactly
     * one of them.
     *
     * @return array{0: list<array{at: DateTimeImmutable, total: int, partial: bool}>, 1: string}
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

        $counted = array_column(
            $this->db->select(
                "SELECT SUBSTR(created_at, 1, {$width}) AS bucket, COUNT(*) AS total
                 FROM activity
                 WHERE {$condition}
                 GROUP BY bucket",
                $bindings,
            ),
            'total',
            'bucket',
        );

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

            $series[] = [
                'at' => $cursor,
                'total' => (int) ($counted[$cursor->format($format)] ?? 0),
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
     * The hover text on one column, which is the only place a reader can put a
     * number to a bucket that is neither the peak nor an edge.
     *
     * @param array{at: DateTimeImmutable, total: int, partial: bool} $bucket
     */
    private function title(array $bucket, string $unit): string
    {
        $when = $this->tick($bucket['at'], $unit);

        if ($unit === 'hour') {
            $when .= '–' . $this->tick($bucket['at']->modify('+1 hour'), $unit);
        }

        return $when . ' · ' . number_format($bucket['total'])
            . ($bucket['total'] === 1 ? ' request' : ' requests')
            . ($bucket['partial'] ? ' · partial' : '');
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
