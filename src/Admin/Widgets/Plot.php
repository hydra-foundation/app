<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use function floor;
use function log10;

/**
 * One series of counts, as the geometry a column chart is drawn from.
 *
 * Separate from the widget because it is arithmetic and nothing else: it knows
 * about values, a ceiling and a viewBox, and nothing about requests. A widget
 * hands it numbers and the words for them, and gets back rectangles.
 *
 * The geometry is computed here rather than in the template because the
 * alternative under this content policy is a style attribute: style-src carries
 * no 'unsafe-inline' and a nonce does not reach a style attribute, so anything
 * positioned by CSS from PHP is refused. Geometry is a presentation attribute
 * and arrives intact.
 */
final readonly class Plot
{
    /** The plot in user units: a 100 by 32 viewBox with a unit of headroom. */
    public const BASE = 31.0;
    public const SPAN = 30.0;

    /** A bucket with something in it never draws as a bucket with none. */
    private const STUB = 0.6;

    /**
     * The most buckets each mark width is drawn for, widest first. A name and
     * not a number because the width itself lives in the sheet: it wants to be
     * the smaller of a share of the card and a ceiling in pixels, and a value
     * computed here could only reach the element as a style attribute, which
     * the content policy refuses.
     */
    private const DENSITIES = [12 => 'is-n12', 24 => 'is-n24', 48 => 'is-n48', 120 => 'is-n120'];

    /**
     * @param list<array{x: float, top: float, partial: bool, title: string}> $bars
     * @param list<array{x: float, width: float, title: string}> $hits
     */
    private function __construct(
        public string $label,
        public string $tone,
        public int $ceiling,
        public string $top,
        public ?string $middle,
        public array $bars,
        public array $hits,
    ) {}

    /**
     * @param list<array{value: int, partial: bool, title: string}> $points
     * @param string $tone which of the chart's roles this series plays, which
     *                     the sheet paints: the subject, a fault, or context
     * @param bool $halfway whether the scale is worth a label between the ends;
     *                      a band two lines tall has no room for a third number
     */
    public static function of(
        string $label,
        string $tone,
        array $points,
        string $suffix = '',
        bool $halfway = true,
    ): self {
        $ceiling = self::ceiling(max([0, ...array_column($points, 'value')]));
        $slot = count($points) === 0 ? 100.0 : 100 / count($points);
        $bars = [];
        $hits = [];

        foreach ($points as $index => $point) {
            $height = $point['value'] === 0
                ? 0.0
                : max(self::STUB, $point['value'] / $ceiling * self::SPAN);

            // A stroked line down the middle of the slot rather than a filled
            // rectangle across it. A rectangle's width is in the viewBox, and
            // the viewBox is stretched to the card, so the same chart drew
            // forty-pixel slabs on a wide card and slivers on a narrow one. A
            // stroke marked non-scaling is exempt from that stretch: it is the
            // one width on the plot that can be asked for in pixels and get
            // them. Nothing is drawn for a bucket of zero — the line has no
            // length and the cap is butt — which is what the baseline is for.
            $bars[] = [
                'x' => round($index * $slot + $slot / 2, 2),
                'top' => round(self::BASE - $height, 2),
                'partial' => $point['partial'],
                'title' => $point['title'],
            ];

            // Full height and the whole slot, not just what the mark covers: a
            // bar three pixels tall is not a hover target, and an empty bucket
            // draws nothing at all, so a reader asking what happened at four in
            // the morning has nothing under the cursor to ask.
            $hits[] = [
                'x' => round($index * $slot, 2),
                'width' => round($slot, 2),
                'title' => $point['title'],
            ];
        }

        return new self(
            $label,
            $tone,
            $ceiling,
            self::figure($ceiling) . $suffix,
            $halfway ? self::figure($ceiling / 2) . $suffix : null,
            $bars,
            $hits,
        );
    }

    /**
     * Which of the sheet's mark widths this many buckets are drawn at.
     *
     * Named for the top of the range it covers and sized for it too, so a
     * window that falls partway up a band draws thinner marks than it strictly
     * needs. Thin bars with air between them are a chart; bars wider than their
     * own slot are a smear with no gaps left to read.
     */
    public static function density(int $buckets): string
    {
        foreach (self::DENSITIES as $most => $class) {
            if ($buckets <= $most) {
                return $class;
            }
        }

        return 'is-nmany';
    }

    /**
     * The number the top of the plot is worth: the peak rounded up to two
     * significant figures.
     *
     * Rounded rather than taken as it comes because the ceiling is a label a
     * reader has to hold in their head while they look at the bars, and 620 is
     * a number one can halve at a glance where 616 is not. Two figures and not
     * one, because rounding 616 up to 1,000 is nearly forty per cent of the
     * plot spent on headroom, and the bars are what the card is for.
     */
    private static function ceiling(int $peak): int
    {
        if ($peak <= 10) {
            // Under ten there is nothing to round to: every value is already
            // its own significant figure, and a ceiling of 1 keeps the divide
            // below safe on an empty series.
            return max($peak, 1);
        }

        $step = 10 ** (int) floor(log10($peak) - 1);

        return (int) ceil($peak / $step) * $step;
    }

    /** Halving an odd ceiling leaves a half, which is a real gridline value. */
    private static function figure(float|int $value): string
    {
        return (float) $value === floor((float) $value)
            ? number_format((float) $value)
            : number_format((float) $value, 1);
    }
}
