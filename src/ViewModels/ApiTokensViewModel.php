<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * The API tokens settings screen: the account's tokens, and a new one's plain
 * value on the one response that shows it.
 */
final readonly class ApiTokensViewModel
{
    use FormErrors;

    /** The expiry select's choices. */
    public const EXPIRIES = ['never' => 'Never', '30' => 'In 30 days', '90' => 'In 90 days', '365' => 'In a year'];

    /**
     * @param list<array{id: int|string, name: string, created: string, used: ?string, expires: ?string}> $tokens
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function __construct(
        public array $tokens,
        public ?string $plain = null,
        public ?string $plainName = null,
        public array $errors = [],
        public array $old = [],
    ) {}

    public function old(string $name, string $default = ''): string
    {
        return $this->old[$name] ?? $default;
    }

    /** @return list<string> */
    private function fields(): array
    {
        return ['label', 'expires'];
    }
}
