<?php

declare(strict_types=1);

namespace App\Authorization;

use Hydra\Authorization\AuthorizeMiddleware;

/**
 * Require admin role middleware
 *
 * Route middleware that admits only admins, by enforcing the app's own ability
 */
final class RequireAdmin extends AuthorizeMiddleware
{
    protected function ability(): string
    {
        return AccessAdmin::class;
    }
}
