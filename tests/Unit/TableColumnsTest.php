<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Support\Column;
use App\Console\Support\TableColumns;
use App\Tests\Support\TestSchema;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The one place in the application that asks the database what it looks like.
 *
 * The dialect is probed rather than configured, so the case that matters is the
 * one a suite on sqlite can still see: that the probe finds the table, keeps the
 * schema's own column order, and says which table it could not read when it
 * finds nothing — an empty list handed to a generator writes a source declaring
 * no columns, which TableSource then refuses at a confusing distance.
 */
#[CoversClass(TableColumns::class)]
final class TableColumnsTest extends TestCase
{
    private TableColumns $tables;

    protected function setUp(): void
    {
        $this->tables = new TableColumns(new PdoConnection(TestSchema::connect()));
    }

    public function test_it_reads_a_table_in_the_order_the_schema_declares_it(): void
    {
        // Order is not cosmetic: it is the order the generated column list, the
        // generated fields and the generated entity's constructor all take.
        $names = array_map(static fn (Column $c): string => $c->name, $this->tables->of('audit'));

        $this->assertSame(
            ['id', 'module', 'table_id', 'old_value', 'new_value', 'user_id', 'username', 'message', 'created_at'],
            $names,
        );
    }

    public function test_it_carries_the_type_each_answer_is_a_guess_from(): void
    {
        $columns = $this->keyed('audit');

        $this->assertTrue($columns['id']->isKey());
        $this->assertTrue($columns['message']->isText());
        $this->assertFalse($columns['user_id']->isText());
        $this->assertTrue($columns['user_id']->looksFilterable());
    }

    public function test_a_table_nobody_created_is_named_in_the_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"ghosts"');

        $this->tables->of('ghosts');
    }

    /** @return array<string, Column> */
    private function keyed(string $table): array
    {
        $columns = [];

        foreach ($this->tables->of($table) as $column) {
            $columns[$column->name] = $column;
        }

        return $columns;
    }
}
