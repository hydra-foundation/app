<?php

declare(strict_types=1);

namespace App\Authorization;

use Hydra\Authorization\AuthorizeMiddleware;

/**
 * Route middleware that admits only admins, by enforcing the app's own
 * {@see AccessAdmin} ability.
 *
 * The package ships the mechanism ({@see AuthorizeMiddleware}: read the gate,
 * 403 on denial); naming the ability is app policy, so it lives here as a one-line
 * subclass referenced by `::class` on a route — the same policy-in-the-app split
 * as the ability itself. Place it INSIDE AuthenticateMiddleware so an anonymous
 * visitor gets a 401 redirect to login rather than a 403 dead end.
 */
final class RequireAdmin extends AuthorizeMiddleware
{
    protected function ability(): string
    {
        return AccessAdmin::class;
    }
}
