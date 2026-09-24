<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * The Security settings screen: whether a second factor is on, the setup in
 * progress if one is, and recovery codes on the one response that shows them.
 */
final readonly class SecurityViewModel
{
    use FormErrors;

    /**
     * @param list<string>|null $codes
     * @param array<string, string> $errors
     */
    public function __construct(
        public bool $enabled,
        public ?string $enabledAt = null,
        public int $remaining = 0,
        public ?string $setupSecret = null,
        public ?string $setupUri = null,
        public ?array $codes = null,
        public array $errors = [],
        public string $form = '',
    ) {}

    public function settingUp(): bool
    {
        return !$this->enabled && $this->setupSecret !== null;
    }

    /** The secret in fours, for typing into an app by hand. */
    public function groupedSecret(): string
    {
        return implode(' ', str_split($this->setupSecret ?? '', 4));
    }

    /** The error on $name, when $form is the one submitted. */
    public function errorIn(string $form, string $name): string
    {
        return $this->form === $form ? $this->error($name) : '';
    }

    /** @return list<string> */
    private function fields(): array
    {
        return ['code', 'current_password'];
    }
}
