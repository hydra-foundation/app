<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * The second step of signing in: one field, which takes the authenticator's
 * code or, with $recovery, one of the recovery codes.
 */
final readonly class TwoFactorViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(
        public bool $recovery = false,
        public array $errors = [],
    ) {}

    /** @return list<string> */
    private function fields(): array
    {
        return ['code'];
    }
}
