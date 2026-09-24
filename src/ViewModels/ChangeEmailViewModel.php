<?php

declare(strict_types=1);

namespace App\ViewModels;

final readonly class ChangeEmailViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(public array $errors = []) {}

    /** @return list<string> */
    private function fields(): array
    {
        return ['email', 'current_password'];
    }
}
