<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Sources\ScheduledRunsSource;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Testing\RowSourceContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ScheduledRunsSource::class)]
final class ScheduledRunsSourceTest extends RowSourceContractTestCase
{
    private const WORKER = 'Hydra\Queue\Worker';
    private const PRUNE = 'Hydra\Scheduler\PruneScheduledRuns';

    private ScheduledRunsSource $source;

    protected function setUp(): void
    {
        $pdo = TestSchema::connect();

        $rows = [
            [self::WORKER, 'ran', 3, null, null],
            [self::WORKER, 'ran', 0, null, null],
            [self::PRUNE, 'ran', null, null, null],
            [self::WORKER, 'failed', null, null, 'RuntimeException: the disk is full'],
            [self::WORKER, 'held', null, 7, null],
            [self::WORKER, 'ran', 0, null, null],
        ];

        foreach ($rows as $n => [$task, $outcome, $items, $held, $error]) {
            $pdo->prepare(
                'INSERT INTO scheduled_runs (task, outcome, items, held_minutes, error, started_at, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$task, $outcome, $items, $held, $error, 1790000000 + $n * 60, 10]);
        }

        $this->source = new ScheduledRunsSource(new PdoConnection($pdo));
    }

    public function test_idle_is_a_run_that_handled_nothing(): void
    {
        $idle = $this->walk(filters: ['idle' => '1']);
        $busy = $this->walk(filters: ['idle' => '0']);

        $this->assertSame(['2', '6'], $this->idsOf($idle));
        $this->assertSame(['1', '3', '4', '5'], $this->idsOf($busy));
        $this->assertSame(['1', '1'], array_column($idle, 'idle'));
        $this->assertSame(['0', '0', '0', '0'], array_column($busy, 'idle'));
    }

    public function test_an_opened_run_says_whether_it_was_idle(): void
    {
        $this->assertSame('1', $this->source->find('2')['idle'] ?? null);
        $this->assertSame('0', $this->source->find('3')['idle'] ?? null);
    }

    public function test_the_list_is_newest_first_when_asked(): void
    {
        $page = $this->source->page(new Criteria(page: 1, perPage: 3, sort: 'id', direction: 'desc'));

        $this->assertSame(['6', '5', '4'], $this->idsOf($page->rows));
    }

    /** @return array<string, string> */
    protected function filterValues(): array
    {
        return ['task' => self::PRUNE, 'outcome' => 'failed', 'idle' => '1'];
    }

    protected function source(): SourceInterface
    {
        return $this->source;
    }

    protected function rowCount(): int
    {
        return 6;
    }

    protected function sortColumn(): string
    {
        return 'id';
    }

    protected function searchMatchingSomeRows(): string
    {
        return 'disk';
    }
}
