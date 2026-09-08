<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the login form: the submitted username (to refill the field on
 * a failed attempt) and any errors to show.
 */
final readonly class LoginViewModel
{
    /** @param array<string, string> $errors */
    public function __construct(
        public string $username = '',
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<string> Every error message, for a flat summary. */
    public function messages(): array
    {
        return array_values($this->errors);
    }
}
