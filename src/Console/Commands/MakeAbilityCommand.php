<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Commands\MakeClassCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates an ability class in App\Authorization, the unit the authorization
 * layer asks when it needs a yes or no.
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
                // Deny by default; fill in the rule. An ability that grants
                // nothing should refuse, not silently allow.
                return false;
            }
        }

        PHP;
    }
}
