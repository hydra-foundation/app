<?php

declare(strict_types=1);

namespace App\ViewModels;

final readonly class ChangePasswordViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(public array $errors = []) {}

    /** @return list<string> */
    private function fields(): array
    {
        return ['current_password', 'password', 'password_confirmation'];
    }
}
