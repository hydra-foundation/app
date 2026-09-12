<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Commands\MakeClassCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates a controller in App\Controllers, already carrying a #[Route] so the
 * new class answers a URL the moment it is written.
 */
#[AsCommand(
    name: 'make:controller',
    description: 'Create a controller in App\\Controllers',
)]
final class MakeControllerCommand extends MakeClassCommand
{
    protected function nameHint(): string
    {
        return 'The controller name, e.g. "post" or "PostController"';
    }

    protected function suffix(): string
    {
        return 'Controller';
    }

    protected function stub(string $class): string
    {
        // The resource the controller is for: PostController → "post". Used for a
        // sensible starter route and template name, both meant to be edited.
        $resource = strtolower(substr($class, 0, -strlen($this->suffix())));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Controllers;

        use Hydra\\Http\\Attributes\\Route;
        use Psr\\Http\\Message\\ResponseInterface;

        final class {$class} extends Controller
        {
            #[Route('/{$resource}')]
            public function index(): ResponseInterface
            {
                return \$this->render('{$resource}');
            }
        }

        PHP;
    }

    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $io->note("Register it: add {$class}::class to App\\Providers\\AppServiceProvider::CONTROLLERS.");
    }
}
