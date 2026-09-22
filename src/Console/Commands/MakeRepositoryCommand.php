<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;

/**
 * Generates a repository in App\Repositories: the application's own way into a
 * table, as opposed to the admin's.
 *
 * Worth writing only when code outside the admin touches the table. A source
 * read by the backend and a repository written by a middleware are two
 * contracts over one table and belong apart; one class holding both is one
 * class with two reasons to change. If the admin is the only thing that ever
 * touches these rows, the source is the whole of it and this command is the
 * wrong one.
 */
#[AsCommand(
    name: 'make:repository',
    description: 'Create a repository in App\\Repositories',
)]
final class MakeRepositoryCommand extends MakeFromTableCommand
{
    protected function nameHint(): string
    {
        return 'The repository name, e.g. "invoice" or "InvoiceRepository"';
    }

    protected function suffix(): string
    {
        return 'Repository';
    }

    protected function stub(string $class): string
    {
        $entity = substr($class, 0, -strlen($this->suffix()));
        $variable = $this->variable($entity);

        $readable = $this->columnNames();
        $written = array_values(array_filter(
            $readable,
            static fn (string $n): bool => !in_array($n, self::NOT_WRITTEN, true),
        ));

        $columns = $this->listLiteral($written, '    ');
        $reads = $this->listLiteral($readable, '    ');
        $arguments = $this->boundValues($written, $entity);
        // The entity's properties, which are every readable column but the key —
        // the same rule make:entity applies. Hydrating only the written ones
        // would leave a required constructor argument unfilled.
        $hydration = $this->hydration(
            array_values(array_filter($readable, static fn (string $n): bool => $n !== 'id')),
        );

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Repositories;

        use App\\Entities\\{$entity};
        use Hydra\\Database\\Contracts\\ConnectionInterface;

        /**
         * The application's side of the {$this->table} table.
         *
         * Every value travels bound. Column names are interpolated because no
         * driver binds an identifier, and they come from the two lists below
         * rather than from anything a caller passed in.
         *
         * If this table has a width a caller can exceed — a message, a name, a
         * path — clip to it here rather than at the call site, the way
         * ActivityRepository does. sqlite stores an over-long VARCHAR without
         * complaint and MariaDB in strict mode refuses the insert, so a suite on
         * the first will never find what production on the second does.
         */
        final class {$class}
        {
            /**
             * What a read returns. Spelled out rather than SELECT *, so a column
             * added to the table later — a hash, a token — does not arrive in
             * every caller of this class the moment the migration runs.
             */
            private const READS = {$reads};

            /** What a write sets. The key and the timestamps are the database's. */
            private const COLUMNS = {$columns};

            public function __construct(private readonly ConnectionInterface \$db) {}

            public function find(int \$id): ?{$entity}
            {
                \$row = \$this->db->selectOne(
                    sprintf('SELECT %s FROM {$this->table} WHERE id = ?', implode(', ', self::READS)),
                    [\$id],
                );

                return \$row === null ? null : \$this->hydrate(\$row);
            }

            public function create({$entity} \${$variable}): int
            {
                \$this->db->execute(
                    sprintf(
                        'INSERT INTO {$this->table} (%s) VALUES (%s)',
                        implode(', ', self::COLUMNS),
                        implode(', ', array_fill(0, count(self::COLUMNS), '?')),
                    ),
                    [
        {$arguments}
                    ],
                );

                return (int) \$this->db->lastInsertId();
            }

            public function delete(int \$id): bool
            {
                return \$this->db->execute('DELETE FROM {$this->table} WHERE id = ?', [\$id]) > 0;
            }

            /** @param array<string, mixed> \$row */
            private function hydrate(array \$row): {$entity}
            {
                return new {$entity}(
        {$hydration}
                );
            }
        }

        PHP;
    }

    /**
     * The row's columns as the entity's constructor arguments. Named, because a
     * positional list is the thing that silently transposes two same-typed
     * fields when a column is added in the middle.
     *
     * @param list<string> $columns
     */
    private function hydration(array $columns): string
    {
        $indent = '            ';

        return implode("\n", array_map(
            fn (string $column): string => sprintf(
                "%s%s: \$row['%s'] === null ? null : (%s) \$row['%s'],",
                $indent,
                $this->camel($column),
                $column,
                $this->cast($column),
                $column,
            ),
            $columns,
        ));
    }

    /** The same read make:entity makes of the column, so the two agree. */
    private function cast(string $column): string
    {
        foreach ($this->columns as $candidate) {
            if ($candidate->name === $column) {
                return preg_match('/int/i', $candidate->type) ? 'int' : 'string';
            }
        }

        return 'string';
    }

    /**
     * The entity's properties in COLUMNS order, as the bound values of the
     * insert. Spelled out rather than looped so the two lists are visibly the
     * same length, which is the mistake this shape is prone to.
     *
     * @param list<string> $columns
     */
    private function boundValues(array $columns, string $entity): string
    {
        $variable = $this->variable($entity);
        $indent = '                ';

        return implode("\n", array_map(
            fn (string $column): string => sprintf('%s$%s->%s,', $indent, $variable, $this->camel($column)),
            $columns,
        ));
    }

    private function variable(string $entity): string
    {
        return lcfirst($entity);
    }

    private function camel(string $column): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
    }

    protected function afterCreate(OutputInterface $output, string $class): void
    {
        $this->reportSecrets($output);
        $output->note(
            'Only write one of these when code outside the admin touches the table. '
            . 'Fill in hydrate(), and clip anything a caller can overrun to its column width.',
        );
    }
}
