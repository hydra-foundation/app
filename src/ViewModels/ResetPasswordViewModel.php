<?php

declare(strict_types=1);

namespace App\ViewModels;

/** The "choose a new password" form, named for the account it will change. */
final readonly class ResetPasswordViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(
        public string $username,
        public array $errors = [],
    ) {}

    /** @return list<string> */
    private function fields(): array
    {
        return ['password', 'password_confirmation'];
    }
}
