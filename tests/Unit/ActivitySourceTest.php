<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Sources\ActivitySource;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Testing\RowSourceContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The activity log's read side, held to the framework's published source
 * contract. It implements no write interface, so the ladder stops at the row
 * case: the log is readable from the admin and not rewritable from it, and the
 * contract case it extends is the statement of which.
 */
#[CoversClass(ActivitySource::class)]
final class ActivitySourceTest extends RowSourceContractTestCase
{
    private PDO $pdo;
    private ActivitySource $source;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();

        // Nothing here carries a %, a _ or a !, which is what lets the wildcard
        // cases read a match for one of those as the term reaching the LIKE
        // unescaped rather than as the data really holding it.
        $rows = [
            ['ada', 'GET', '/one', 200, '10.0.0.1'],
            ['grace', 'POST', '/two', 302, '10.0.0.2'],
            ['alan', 'GET', '/three', 404, '10.0.0.3'],
            ['edsger', 'GET', '/four', 200, '10.0.0.4'],
            ['barbara', 'DELETE', '/five', 500, '10.0.0.5'],
        ];

        foreach ($rows as [$username, $method, $path, $status, $ip]) {
            $this->pdo->prepare(
                'INSERT INTO activity (username, method, path, status, duration_ms, ip) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([$username, $method, $path, $status, 5, $ip]);
        }

        $this->source = new ActivitySource(new PdoConnection($this->pdo));
    }

    /** @return array<string, string> */
    protected function filterValues(): array
    {
        // Three GETs and two 200s of the five, so neither reads as unfiltered.
        return ['method' => 'GET', 'status' => '200'];
    }

    protected function source(): SourceInterface
    {
        return $this->source;
    }

    protected function rowCount(): int
    {
        return 5;
    }

    protected function sortColumn(): string
    {
        return 'id';
    }

    protected function searchMatchingSomeRows(): string
    {
        // One of the five usernames, and a substring of no path or address, so
        // a narrowing search is distinguishable from an ignored one.
        return 'grace';
    }
}
