<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds a controller under `App\Controllers` — the base-class extension,
 * route attribute and imports that are easy to mistype by hand.
 *
 * The "Controller" suffix is guaranteed, and a starter `index()` route is
 * derived from the resource name (PostController → `#[Route('/post')]` rendering
 * the `post` template). It does NOT touch `AppServiceProvider::CONTROLLERS`:
 * that list is read by hand for a reason (the whole route contract in one place),
 * so the command emits a reminder instead of rewriting source — see
 * {@see afterCreate()}.
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
