<?php

declare(strict_types=1);

namespace App\ViewModels;

/** The "send me a reset link" form, and the one answer it gives once sent. */
final readonly class ForgotPasswordViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(
        public string $email = '',
        public array $errors = [],
        public ?string $status = null,
    ) {}

    /** @return list<string> */
    private function fields(): array
    {
        return ['email'];
    }
}
