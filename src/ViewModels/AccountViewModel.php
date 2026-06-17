<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the protected account page: just the signed-in user's name.
 *
 * The guard hands back an {@see \Hydra\Auth\Contracts\AuthenticatableInterface},
 * whose surface is only the id and password hash — never the username. The
 * controller narrows that to the app's {@see \App\Entities\User} to fill this
 * VO, so the template depends on a plain string, not on the auth contract.
 */
final readonly class AccountViewModel
{
    public function __construct(public string $username) {}
}
