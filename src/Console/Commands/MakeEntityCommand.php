<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\Column;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates an entity in App\Entities: the shape of one row, and nothing else.
 *
 * Readonly and constructor-promoted, because an entity here is a value and not
 * a record with behaviour — there is no ORM, nothing tracks it, and a setter on
 * one would be a change nothing would ever persist. The types are a first pass
 * over the column types; a nullable column the generator read as required is
 * one line to fix, and it is visible.
 */
#[AsCommand(
    name: 'make:entity',
    description: 'Create an entity in App\\Entities',
)]
final class MakeEntityCommand extends MakeFromTableCommand
{
    /**
     * The key is left out. An entity is what a row *says*, and the id is what
     * the database calls it: a create() has no id to pass and reading one back
     * is the repository's business. ActivityRepository and AuditRepository both
     * take an entity with no id for exactly this reason.
     */
    private const NOT_A_PROPERTY = ['id'];

    protected function nameHint(): string
    {
        return 'The entity name, e.g. "invoice" or "Invoice"';
    }

    protected function suffix(): string
    {
        return '';
    }

    protected function stub(string $class): string
    {
        $properties = [];

        foreach ($this->columns as $column) {
            if ($column->isSecret() || in_array($column->name, self::NOT_A_PROPERTY, true)) {
                continue;
            }

            $properties[] = sprintf(
                '        public %s $%s,',
                $this->type($column),
                $this->camel($column->name),
            );
        }

        $body = implode("\n", $properties);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Entities;

        /**
         * One row of {$this->table}.
         *
         * Every property is nullable because the generator cannot see which
         * columns the schema requires. Tighten the ones that are never null —
         * a type that says so is the cheapest check in the application.
         */
        final readonly class {$class}
        {
            public function __construct(
        {$body}
            ) {}
        }

        PHP;
    }

    /**
     * Nullable everything, on purpose. NOT NULL is not visible in what this
     * reads, and a type claiming a column is always present when it is not
     * fails at the one moment it matters — halfway through a write.
     */
    private function type(Column $column): string
    {
        if ($column->isTemporal() || $column->isText()) {
            return '?string';
        }

        return preg_match('/int/i', $column->type) ? '?int' : '?string';
    }

    private function camel(string $column): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
    }

    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $this->reportSecrets($io);
    }
}
