<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Commands\MakeClassCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates an event listener in App\Listeners: one invokable class that hears
 * one event and does something with it.
 *
 * A listener rather than a service called by hand is the difference between a
 * thing that records every write and a thing that records the writes somebody
 * remembered to call it about. The audit trail is the worked example — the
 * admin already announces its writes, so no module has to know the log exists.
 */
#[AsCommand(
    name: 'make:listener',
    description: 'Create an event listener in App\\Listeners',
)]
final class MakeListenerCommand extends MakeClassCommand
{
    private string $event = 'AdminEvent';

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'event',
            'e',
            InputOption::VALUE_REQUIRED,
            'The event class it hears, short or fully qualified. Defaults to Hydra\\Admin\\Events\\AdminEvent.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $given = $input->getOption('event');
        $event = is_string($given) && $given !== '' ? $given : 'Hydra\\Admin\\Events\\AdminEvent';

        // A backslash is the shell's escape character as well as PHP's namespace
        // separator, so what arrives here has been doubled, or not, depending on
        // the quoting somebody used. Collapsing runs of them means all four
        // spellings of the same class name land as one import.
        $this->event = trim((string) preg_replace('/\\\\+/', '\\', $event), '\\');

        return parent::execute($input, $output);
    }

    protected function nameHint(): string
    {
        return 'The listener name, e.g. "AuditAdminEvents"';
    }

    protected function suffix(): string
    {
        return 'Listener';
    }

    protected function stub(string $class): string
    {
        $fqcn = ltrim($this->event, '\\');
        $short = str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
        $import = str_contains($fqcn, '\\') ? "use {$fqcn};\n" : '';

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Listeners;

        {$import}use Psr\\Log\\LoggerInterface;
        use Throwable;

        /**
         * Hears {$short}.
         *
         * Invokable rather than a named method, so registering it is one line and
         * the class has one job. A listener that needs to hear several events
         * takes a method each and is registered several times; one that grows a
         * switch over the event type is two listeners wearing a coat.
         */
        final class {$class}
        {
            public function __construct(private readonly LoggerInterface \$logger) {}

            public function __invoke({$short} \$event): void
            {
                // Swallowed on purpose, the way the audit listener swallows its own
                // write: the thing that raised the event succeeded, and failing the
                // request afterwards would undo nothing and report the wrong cause.
                // Delete this if a failure here genuinely should fail the request.
                try {
                    // ...
                } catch (Throwable \$e) {
                    \$this->logger->error('{$class} failed', ['exception' => \$e]);
                }
            }
        }

        PHP;
    }

    /**
     * The registration is the half a generator cannot write, and the half with
     * the trap in it, so it is printed in full rather than summarised.
     */
    protected function afterCreate(SymfonyStyle $io, string $class): void
    {
        $io->note("Register it in AppServiceProvider::boot(), as a closure rather than an instance:");
        $io->writeln(<<<TXT
             \$listeners->listen({$this->shortEvent()}::class, static function ({$this->shortEvent()} \$event) use (\$container): void {
                 (\$container->get({$class}::class))(\$event);
             });

         The closure is not style. boot() runs before anything rebinds
         ConnectionInterface, and every integration harness rebinds one right
         after, so a listener built at boot goes on writing to whichever database
         was bound then — and the tests pass while recording nothing.
        TXT);
    }

    private function shortEvent(): string
    {
        $fqcn = ltrim($this->event, '\\');

        return str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
    }
}
