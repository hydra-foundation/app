<?php

declare(strict_types=1);

namespace App\View;

/**
 * What palettes this installation offers, read from the directory they live in
 * rather than from a list here. A theme is one CSS file of colour tokens, so
 * dropping one in is the whole of adding it — the picker, the validation and
 * the stylesheet links all follow from the same directory listing.
 */
final class Themes
{
    /** The palette a visitor gets before anyone has chosen one. */
    public const FALLBACK = 'paper';

    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(private readonly string $path) {}

    /** @return list<string> */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.css'),
            glob($this->path . '/*.css') ?: [],
        );

        sort($names);

        // The fallback has to be offered even if the directory is unreadable,
        // or a bad deploy would leave a picker with nothing in it.
        return $this->names = $names === [] ? [self::FALLBACK] : $names;
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /** A name the picker can show: "high-contrast" reads as "High contrast". */
    public function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    /** @return array<string, string> name => label, in listing order */
    public function options(): array
    {
        $options = [];

        foreach ($this->names() as $name) {
            $options[$name] = $this->label($name);
        }

        return $options;
    }

    /** The name to use, given what someone asked for. */
    public function resolve(?string $name): string
    {
        return $name !== null && $this->has($name) ? $name : self::FALLBACK;
    }
}
