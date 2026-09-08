<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * Login view model
 *
 * View model for the login form: the submitted username (to refill the field on
 * a failed attempt) and any errors to show
 */
final readonly class LoginViewModel
{
    public function __construct(
        public string $username = '',
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function messages(): array
    {
        return array_values($this->errors);
    }
}
