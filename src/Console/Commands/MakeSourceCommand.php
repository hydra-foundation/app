<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\Column;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates an admin source in App\Admin\Sources: the module's data contract,
 * and the one class a module can never do without.
 *
 * Over a plain table a source is a declaration rather than code, so this writes
 * the declaration — the table, the columns it reads, and which of those sort,
 * search and filter — from the schema itself. Everything it guesses is visible
 * in the file it wrote, which is the point: the lists are meant to be narrowed
 * by hand, and a generated line you disagree with is one you delete.
 */
#[AsCommand(
    name: 'make:source',
    description: 'Create an admin source in App\\Admin\\Sources',
)]
final class MakeSourceCommand extends MakeFromTableCommand
{
    /**
     * Columns a generated write never touches. The key is the database's to
     * issue and the timestamps are its to keep; a form that posted any of them
     * would be a form overwriting the record of when the row happened.
     */
    private const NOT_WRITABLE = ['id', 'created_at', 'updated_at'];

    private bool $writable = false;

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'writable',
            'w',
            InputOption::VALUE_NONE,
            'Add the create, update and delete contracts as well as the read side.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->writable = (bool) $input->getOption('writable');

        return parent::execute($input, $output);
    }

    protected function nameHint(): string
    {
        return 'The source name, e.g. "invoice" or "InvoiceSource"';
    }

    protected function suffix(): string
    {
        return 'Source';
    }

    protected function stub(string $class): string
    {
        $names = $this->columnNames();

        $declaration = $this->declaration(
            columns: $this->listLiteral($names),
            sortable: $this->listLiteral($names),
            searchable: $this->listLiteral($this->matching(
                static fn (Column $c): bool => $c->isText() && !$c->isKey(),
            )),
            filterable: $this->listLiteral($this->matching(
                static fn (Column $c): bool => $c->looksFilterable(),
            )),
            sort: in_array('id', $names, true) ? 'id' : ($names[0] ?? 'id'),
        );

        return $this->writable
            ? $this->writableStub($class, $declaration)
            : $this->readOnlyStub($class, $declaration);
    }

    private function declaration(
        string $columns,
        string $sortable,
        string $searchable,
        string $filterable,
        string $sort,
    ): string {
        return <<<PHP
        parent::__construct(
                    \$db,
                    table: '{$this->table}',
                    columns: {$columns},
                    sortable: {$sortable},
                    searchable: {$searchable},
                    filterable: {$filterable},
                    defaultSort: '{$sort}',
                );
        PHP;
    }

    private function readOnlyStub(string $class, string $declaration): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Admin\\Sources;

        use Hydra\\Admin\\Sources\\TableSource;
        use Hydra\\Database\\Contracts\\ConnectionInterface;

        /**
         * {$this->table} module data contract, read-only.
         *
         * Read-only is expressed by implementing no write contract: the registry
         * raises on a screen asking for one this does not have, at boot rather
         * than on the request that would have used it.
         */
        final class {$class} extends TableSource
        {
            public function __construct(ConnectionInterface \$db)
            {
                {$declaration}
            }
        }

        PHP;
    }

    /**
     * The write side is generated working rather than stubbed, because over one
     * table it genuinely is boilerplate — but only the mechanical half.
     *
     * WRITABLE is spelled out instead of taken from the keys of $data, so what
     * this class will write is a fact about the class and not about whatever
     * arrived. Policy is the half no schema can answer: what a blank field
     * means, which rows may not go, what is unique against every row but the one
     * being written. That goes in the guards, which is why they are here empty
     * rather than absent.
     */
    private function writableStub(string $class, string $declaration): string
    {
        $writable = $this->listLiteral(
            array_values(array_filter(
                $this->columnNames(),
                static fn (string $n): bool => !in_array($n, self::NOT_WRITABLE, true),
            )),
            '    ',
        );

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Admin\\Sources;

        use Hydra\\Admin\\Contracts\\CreateSourceInterface;
        use Hydra\\Admin\\Contracts\\DeleteSourceInterface;
        use Hydra\\Admin\\Contracts\\UpdateSourceInterface;
        use Hydra\\Admin\\Exceptions\\WriteRejected;
        use Hydra\\Admin\\RowId;
        use Hydra\\Admin\\Sources\\TableSource;
        use Hydra\\Database\\Contracts\\ConnectionInterface;

        /**
         * {$this->table} module data contract.
         *
         * The read side is the declaration below. The write side is written out,
         * because write policy is the part that is never boilerplate: refuse a
         * write with WriteRejected, keyed by input name, and the message lands on
         * the field that caused it.
         */
        final class {$class} extends TableSource implements CreateSourceInterface, UpdateSourceInterface, DeleteSourceInterface
        {
            /** The columns a write may set. The key and the timestamps are not ours. */
            private const WRITABLE = {$writable};

            public function __construct(ConnectionInterface \$db)
            {
                {$declaration}
            }

            /** @param array<string, mixed> \$data */
            public function create(array \$data): string
            {
                \$values = \$this->writable(\$data);

                if (\$values === []) {
                    throw WriteRejected::on('id', 'Nothing to write.');
                }

                \$this->db->execute(
                    sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        \$this->table,
                        implode(', ', array_keys(\$values)),
                        implode(', ', array_fill(0, count(\$values), '?')),
                    ),
                    array_values(\$values),
                );

                return \$this->db->lastInsertId();
            }

            /** @param array<string, mixed> \$data */
            public function update(string \$id, array \$data): void
            {
                \$key = RowId::int(\$id) ?? throw WriteRejected::on('id', 'No row has that id.');
                \$values = \$this->writable(\$data);

                if (\$values === []) {
                    return;
                }

                \$this->db->execute(
                    sprintf(
                        'UPDATE %s SET %s WHERE id = ?',
                        \$this->table,
                        implode(', ', array_map(
                            static fn (string \$column): string => \$column . ' = ?',
                            array_keys(\$values),
                        )),
                    ),
                    [...array_values(\$values), \$key],
                );
            }

            public function delete(string \$id): void
            {
                \$key = RowId::int(\$id) ?? throw WriteRejected::on('id', 'No row has that id.');

                \$this->db->execute(sprintf('DELETE FROM %s WHERE id = ?', \$this->table), [\$key]);
            }

            /**
             * The submitted values this class will write, in WRITABLE's order and
             * no other. A key nobody declared is dropped here rather than reaching
             * the SQL, which is what keeps a column name out of the statement
             * unless this file names it.
             *
             * @param array<string, mixed> \$data
             * @return array<string, scalar|null>
             */
            private function writable(array \$data): array
            {
                \$values = [];

                foreach (self::WRITABLE as \$column) {
                    if (array_key_exists(\$column, \$data)) {
                        /** @var scalar|null \$value */
                        \$value = \$data[\$column];
                        \$values[\$column] = \$value;
                    }
                }

                return \$values;
            }
        }

        PHP;
    }

    /**
     * @param callable(Column): bool $matches
     * @return list<string>
     */
    private function matching(callable $matches): array
    {
        return array_values(array_map(
            static fn (Column $c): string => $c->name,
            array_filter(
                $this->columns,
                static fn (Column $c): bool => !$c->isSecret() && $matches($c),
            ),
        ));
    }

    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $this->reportSecrets($io);
        $io->note(
            'Narrow the lists by hand — sortable is every column, searchable is the text ones, '
            . 'and filterable is a guess. Then run admin:check to hold the module to them.',
        );
    }
}
