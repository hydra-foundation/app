<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the login form: the submitted username (to refill the field on
 * a failed attempt) and any errors to show
 */
final readonly class LoginViewModel
{
    use FormErrors;

    public function __construct(
        public string $username = '',
        public array $errors = [],
    ) {}

    private function fields(): array
    {
        return ['username', 'password'];
    }
}
