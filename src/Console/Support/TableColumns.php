<?php

declare(strict_types=1);

namespace App\Console\Support;

use Hydra\Database\Contracts\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Reads a table's column names out of the connected database, for the
 * generators that would otherwise make you type them.
 *
 * This is the one place in the application that asks the database what it
 * looks like, and it is deliberately a *generator's* tool rather than a
 * runtime one. `TableSource` says in as many words that the schema is never
 * inspected and a column the admin reads is a column you named; discovering
 * them here does not soften that, because what this writes is the literal
 * declaration, in code, that you then read and edit. The alternative is not
 * "no discovery" — it is copying the list out of the migration by hand, which
 * is discovery with a worse transcriber.
 *
 * Two dialects, probed rather than configured. `ConnectionInterface` is narrow
 * on purpose and carries no driver name, and adding one to it so a code
 * generator can branch would be the tail wagging the dog.
 */
final class TableColumns
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @return list<Column>
     * @throws RuntimeException when the table cannot be read, or holds no columns
     */
    public function of(string $table): array
    {
        $columns = $this->sqlite($table) ?? $this->mysql($table);

        if ($columns === null) {
            throw new RuntimeException(
                "Could not read the schema for \"{$table}\". Is the database up, and has the migration run?",
            );
        }

        if ($columns === []) {
            throw new RuntimeException("The database has no table named \"{$table}\".");
        }

        return $columns;
    }

    /**
     * sqlite's pragma, as a table-valued function so the name can be bound. The
     * older `PRAGMA table_info(x)` form interpolates, which is the thing worth
     * avoiding even here: a table name reaching this from a shell argument is
     * still a string somebody typed.
     *
     * @return list<Column>|null null when this is not sqlite
     */
    private function sqlite(string $table): ?array
    {
        try {
            return $this->columns($this->db->select(
                'SELECT name, type FROM pragma_table_info(?) ORDER BY cid',
                [$table],
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<Column>|null null when this is not MySQL/MariaDB
     */
    private function mysql(string $table): ?array
    {
        try {
            return $this->columns($this->db->select(
                'SELECT column_name AS name, data_type AS type
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?
               ORDER BY ordinal_position',
                [$table],
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Column>
     */
    private function columns(array $rows): array
    {
        return array_values(array_map(
            // information_schema answers COLUMN_NAME in upper case on some
            // configurations and column_name on others; the alias settles it,
            // but the fallback keeps a surprise from arriving as an empty list.
            static fn (array $row): Column => new Column(
                (string) ($row['name'] ?? $row['NAME'] ?? ''),
                (string) ($row['type'] ?? $row['TYPE'] ?? ''),
            ),
            $rows,
        ));
    }
}
