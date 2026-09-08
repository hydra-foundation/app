<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the protected account page: just the signed-in user's name.
 */
final readonly class AccountViewModel
{
    public function __construct(public string $username) {}
}
