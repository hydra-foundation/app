<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Commands\MakeClassCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffolds an authorization ability under `App\Authorization` — the app's own
 * policy "noun" that the hydra/authorization package deliberately does not ship.
 *
 * The stub **denies by default** (returns false): an ability that does nothing
 * should refuse, never silently grant, so a half-written rule fails closed. No
 * suffix is forced (abilities read as verbs — AccessAdmin, ManageUser), and no
 * registration reminder is printed: abilities are referenced by `::class` at
 * their use site (a gate call or an AuthorizeMiddleware subclass), so there is no
 * central list to update.
 */
#[AsCommand(
    name: 'make:ability',
    description: 'Create an authorization ability in App\\Authorization',
)]
final class MakeAbilityCommand extends MakeClassCommand
{
    protected function nameHint(): string
    {
        return 'The ability name, e.g. "ManagePost"';
    }

    protected function suffix(): string
    {
        return '';
    }

    protected function stub(string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Authorization;

        use Hydra\\Auth\\Contracts\\AuthenticatableInterface;
        use Hydra\\Authorization\\Contracts\\AbilityInterface;

        final class {$class} implements AbilityInterface
        {
            public function authorize(?AuthenticatableInterface \$user, mixed \$subject = null): bool
            {
                // Deny by default — fill in the rule. An ability that grants
                // nothing should refuse, not silently allow.
                return false;
            }
        }

        PHP;
    }
}
