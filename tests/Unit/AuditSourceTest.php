<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Sources\AuditSource;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Testing\RowSourceContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The audit log's read side, held to the framework's published source contract.
 * It implements no write interface, so the ladder stops at the row case: what
 * was changed is readable from the admin and not rewritable from it.
 *
 * The filter cases below are not part of that contract. They are here for the
 * SQL — that a declared filter reaches it, and that an undeclared one builds no
 * clause rather than an empty result. Whether the module and the source agree
 * on the column *name* is no longer asked here: AdminModulePairingTest asks it
 * of every module at once.
 */
#[CoversClass(AuditSource::class)]
final class AuditSourceTest extends RowSourceContractTestCase
{
    private PDO $pdo;
    private AuditSource $source;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();

        // No searchable value carries a %, a _ or a !, which is what lets the
        // wildcard cases read a match for one of those as the term reaching the
        // LIKE unescaped rather than as the data really holding it. That rules
        // out a real slug like user_preferences, hence the invented ones.
        $rows = [
            ['users', '1', 'ada', 'created account'],
            ['users', '2', 'grace', 'changed role to admin'],
            ['posts', '7', 'alan', 'published'],
            ['invoices', '42', 'edsger', 'voided'],
            ['users', '3', 'barbara', 'deleted account'],
        ];

        foreach ($rows as [$table, $id, $username, $message]) {
            $this->pdo->prepare(
                'INSERT INTO audit (module, table_id, old_value, new_value, user_id, username, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$table, $id, '{"role":"user"}', '{"role":"admin"}', 1, $username, $message]);
        }

        $this->source = new AuditSource(new PdoConnection($this->pdo));
    }

    protected function filterValues(): array
    {
        // Three of the five rows, so a dropped filter reads as five.
        return ['module' => 'users'];
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
        // One of the five usernames, and a substring of no table, row id or
        // message, so a narrowing search is distinguishable from an ignored one.
        return 'grace';
    }

    public function test_the_module_filter_narrows_the_list(): void
    {
        $page = $this->source->page(new Criteria(filters: ['module' => 'posts']));

        $this->assertSame(1, $page->total);
        $this->assertSame('posts', $page->rows[0]['module']);
    }

    public function test_a_filter_the_source_does_not_declare_is_ignored(): void
    {
        // Not narrowed to nothing: an undeclared key builds no clause at all, so
        // the honest answer is the unfiltered table.
        $this->assertSame(5, $this->source->page(new Criteria(filters: ['username' => 'ada']))->total);
    }

    public function test_the_before_and_after_values_are_read_even_though_the_table_hides_them(): void
    {
        // ShowScreen renders them, and it renders from the same row find() hands
        // back, so a source that trimmed them to keep the list cheap would leave
        // the show screen with nothing to display.
        $row = $this->source->find($this->idOf($this->walk()[0]));

        $this->assertSame('{"role":"user"}', $row['old_value']);
        $this->assertSame('{"role":"admin"}', $row['new_value']);
    }
}
