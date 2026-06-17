<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader for a request's parsed body (form fields).
 *
 * The sibling of {@see Htmx}: built from the request in a controller —
 * `Input::fromRequest($request)` — to collapse PSR-7's untyped body surface
 * (`getParsedBody()` returns array|object|null) into a few typed, defensive
 * accessors, instead of casting by hand at every call site.
 *
 * Accessors are falsy-safe: "0" is a present string and 0 is a present int;
 * only genuinely absent or wrong-shaped values fall back to the default.
 */
final class Input
{
    /** @var array<string, mixed> */
    private readonly array $body;

    public function __construct(ServerRequestInterface $request)
    {
        $this->body = (array) $request->getParsedBody();
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self($request);
    }

    /** True if the field was submitted at all (even as an empty string). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /**
     * The field as a string. A missing field, or one submitted as an array
     * (e.g. `name[]`), yields the default — never a TypeError. Not trimmed:
     * trimming is the caller's choice (a password's spaces may matter).
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * The field as an int, or the default when it is absent or non-numeric.
     * "0" reads as 0 (numeric), "" and "abc" read as the default.
     */
    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->body[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
