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
 *
 * The count itself is not here any more — it is the first figure in the strip
 * above the grid, which is where the period's headline numbers live. What this
 * card is for is the thing no figure can say, which is when they arrived.
 *
 * The window arrives resolved and its bounds are bound as values rather than
 * written as SQL date arithmetic, because `NOW() - INTERVAL 1 DAY` and
 * `datetime('now', '-1 day')` are not the same string and this has to answer
 * on both.
 */
final class TrafficWidget implements PeriodAwareInterface
{
    /** Up to two days of requests is worth an hour a point; past that it is noise. */
    private const HOURLY = 2 * 86400;

    /** And past this many days, a point a day is more points than there is line. */
    private const DAILY = 400 * 86400;

    /** A guard on the loop, not a design: no window should reach it. */
    private const POINTS = 1000;

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
     * The line itself, as the attribute a <polyline> takes.
     *
     * Drawn here rather than in the template because it is arithmetic, and
     * because the alternative under this content policy is a style attribute:
     * style-src carries no 'unsafe-inline' and a nonce does not reach a style
     * attribute, so anything positioned by CSS from PHP is refused. A points
     * list is a presentation attribute and arrives intact.
     *
     * @return array{points: string, peak: int, unit: string}|null
     */
    private function spark(): ?array
    {
        [$counts, $unit] = $this->buckets();

        if (count($counts) < 2) {
            return null;
        }

        $peak = max($counts);
        $last = count($counts) - 1;
        $points = [];

        foreach ($counts as $index => $value) {
            // A viewBox of 100 by 32, stretched to the card by the SVG itself.
            // One unit of headroom top and bottom so the peak and the floor are
            // both a line rather than a clipped edge.
            $points[] = round($index / $last * 100, 2) . ','
                . round($peak === 0 ? 31 : 31 - $value / $peak * 30, 2);
        }

        return ['points' => implode(' ', $points), 'peak' => $peak, 'unit' => $unit];
    }

    /**
     * Requests per bucket across the window, zeroes included, and the word for
     * what a bucket is.
     *
     * Grouped on a prefix of the stored timestamp, which both dialects will cut
     * out of a datetime without being told how, and walked in UTC because that
     * is what the column holds: a bucket is a range of instants either way, and
     * lining the walk up with the storage is what keeps every row in exactly
     * one of them. Which hour of the reader's day a bucket starts on does not
     * arise — the line carries no axis, only a shape.
     *
     * @return array{0: list<int>, 1: string}
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
            $series[] = (int) ($counted[$cursor->format($format)] ?? 0);
            $cursor = $cursor->modify('+1 ' . $unit);
        }

        return [$series, $unit];
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
