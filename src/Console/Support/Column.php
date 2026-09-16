<?php

declare(strict_types=1);

namespace App\Console\Support;

/**
 * One column as the database describes it, reduced to the three questions a
 * generator actually asks: is this the key, is it text, is it a time.
 *
 * The raw type is kept alongside because the answers are heuristics and the
 * generated code is meant to be read and corrected. A guess that is visible in
 * the output is a guess somebody fixes; one buried in a rule is one they
 * inherit.
 */
final readonly class Column
{
    public function __construct(
        public string $name,
        public string $type,
    ) {}

    /**
     * A column whose value must not reach a screen: a hash, a token, a secret.
     *
     * The generators leave these out of the column list entirely rather than
     * hiding them downstream, which is what UserSource does by hand with
     * password_hash. The two failure directions are not symmetric — a column
     * wrongly left out is one line to add back and you find it immediately,
     * while one wrongly left in is a credential rendered into every list, show
     * screen and CSV export, and nothing anywhere complains.
     *
     * Matched on the name because the name is what a developer chose to call
     * it, and a column called password_hash is called that for a reason.
     */
    public function isSecret(): bool
    {
        return (bool) preg_match(
            '/pass(word)?|secret|token|salt|_hash$|^hash$|api_?key|private_?key|credential/i',
            $this->name,
        );
    }

    public function isKey(): bool
    {
        return $this->name === 'id';
    }

    /**
     * Text-ish, and therefore a candidate for the search box. A blob or a large
     * TEXT column is excluded: searching one is a table scan wearing a LIKE, and
     * the column it most often is — a serialised payload, a rendered body — is
     * not what anybody means when they type into the box.
     */
    public function isText(): bool
    {
        return (bool) preg_match('/^(var)?char|^(tiny|medium)?text$/i', $this->type);
    }

    public function isTemporal(): bool
    {
        return (bool) preg_match('/^(date|datetime|timestamp)/i', $this->type);
    }

    /**
     * A foreign key or a flag — few distinct values, so a filter over it is a
     * usable one. Only a suggestion: which columns deserve a filter is a
     * question about the screen, not about the schema.
     */
    public function looksFilterable(): bool
    {
        return str_ends_with($this->name, '_id')
            || str_starts_with($this->name, 'is_')
            || in_array($this->name, ['status', 'state', 'type', 'kind', 'role', 'method'], true);
    }
}
