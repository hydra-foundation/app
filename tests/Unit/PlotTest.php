<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Widgets\Plot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic a column chart is drawn from. Worth pinning because none of it
 * is visible in a failure: a wrong ceiling draws a plausible chart of the wrong
 * shape, and nothing throws.
 */
#[CoversClass(Plot::class)]
final class PlotTest extends TestCase
{
    public function test_a_bar_is_a_share_of_the_ceiling_and_not_of_the_peak(): void
    {
        $plot = self::plot([50, 100]);

        // The ceiling rounds 100 up to itself, so the taller bar reaches the
        // top of the span and the other stands at half of it.
        $this->assertSame(100, $plot->ceiling);
        $this->assertSame(Plot::BASE - 15.0, $plot->bars[0]['top']);
        $this->assertSame(Plot::BASE - 30.0, $plot->bars[1]['top']);
    }

    public function test_the_floor_is_the_baseline_and_the_peak_leaves_headroom(): void
    {
        $plot = self::plot([0, 100]);

        // A bar that stops where it started has no length, and a line with no
        // length and a butt cap draws nothing at all.
        $this->assertSame(Plot::BASE, $plot->bars[0]['top']);
        $this->assertSame(Plot::BASE - Plot::SPAN, $plot->bars[1]['top']);
    }

    public function test_a_bucket_with_one_request_does_not_draw_as_an_empty_one(): void
    {
        // A fifth of a per cent of the span is no pixels at all, and an hour
        // that served a request must not look like an hour that served none.
        $this->assertLessThan(Plot::BASE, self::plot([1, 600])->bars[0]['top']);
        $this->assertSame(Plot::BASE, self::plot([0, 600])->bars[0]['top']);
    }

    public function test_a_bar_stands_in_the_middle_of_its_own_slot(): void
    {
        $plot = self::plot([1, 1, 1, 1]);

        $this->assertSame(12.5, $plot->bars[0]['x']);
        $this->assertSame(37.5, $plot->bars[1]['x']);
    }

    public function test_the_hit_target_covers_the_whole_slot_and_not_just_the_mark(): void
    {
        $plot = self::plot([1, 1, 1, 1]);

        $this->assertSame(0.0, $plot->hits[0]['x']);
        $this->assertSame(25.0, $plot->hits[0]['width']);
        $this->assertSame(25.0, $plot->hits[1]['x']);
    }

    public function test_the_mark_width_is_named_for_the_top_of_the_band_it_falls_in(): void
    {
        $this->assertSame('is-n12', Plot::density(8));
        $this->assertSame('is-n24', Plot::density(13));
        $this->assertSame('is-n24', Plot::density(24));
        $this->assertSame('is-n48', Plot::density(25));
        $this->assertSame('is-n120', Plot::density(120));
        $this->assertSame('is-nmany', Plot::density(365));
    }

    #[DataProvider('peaks')]
    public function test_the_ceiling_is_the_peak_rounded_to_two_figures(int $peak, int $ceiling, string $top): void
    {
        $plot = self::plot([$peak]);

        $this->assertSame($ceiling, $plot->ceiling);
        $this->assertSame($top, $plot->top);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function peaks(): iterable
    {
        yield 'a single figure is already exact' => [7, 7, '7'];
        yield 'two figures are left alone' => [98, 98, '98'];
        yield 'three round up to two' => [114, 120, '120'];
        yield 'and so does a near miss' => [616, 620, '620'];
        yield 'thousands keep two figures' => [2058, 2100, '2,100'];
        yield 'an exact power stays put' => [1000, 1000, '1,000'];
        // An empty series still needs a scale to divide by.
        yield 'nothing at all' => [0, 1, '1'];
    }

    public function test_an_odd_ceiling_halves_to_a_gridline_that_can_be_written_down(): void
    {
        $this->assertSame('3.5', self::plot([7])->middle);
        $this->assertSame('60', self::plot([114])->middle);
    }

    public function test_a_band_too_short_for_three_numbers_is_given_two(): void
    {
        $this->assertNull(Plot::of('Failed', 'fault', self::points([2]), halfway: false)->middle);
    }

    public function test_the_suffix_travels_with_the_scale_and_not_with_the_bars(): void
    {
        $this->assertSame('120ms', Plot::of('Slowest', 'slow', self::points([114]), 'ms')->top);
    }

    /** @param list<int> $values */
    private static function plot(array $values): Plot
    {
        return Plot::of('Requests', 'lead', self::points($values));
    }

    /**
     * @param list<int> $values
     * @return list<array{value: int, partial: bool, title: string}>
     */
    private static function points(array $values): array
    {
        return array_map(
            fn (int $value): array => ['value' => $value, 'partial' => false, 'title' => (string) $value],
            $values,
        );
    }
}
