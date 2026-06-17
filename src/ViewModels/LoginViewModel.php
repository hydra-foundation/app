<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the login form: the submitted username (to refill the field on
 * a failed attempt) and any errors to show.
 *
 * A typed view model the login template annotates once. The password is
 * deliberately never carried back — a re-render always starts the password field
 * empty. Errors are keyed,
 * but the login template renders them as a single summary because a failed login
 * must NOT reveal which half was wrong (see the generic credentials message in
 * {@see \App\Controllers\AuthController}).
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
