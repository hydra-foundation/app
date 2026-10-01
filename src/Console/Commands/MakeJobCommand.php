<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Commands\MakeClassCommand;
use Hydra\Console\Contracts\OutputInterface;

/**
 * Generates a queued job in App\Jobs: one class the worker builds from the
 * container and hands the payload it was pushed with.
 *
 * No suffix is forced on the name. A job is named for what it does
 * (SendVerificationLink), and "Job" on the end of every one says nothing.
 */
#[AsCommand(
    name: 'make:job',
    description: 'Create a queued job in App\\Jobs',
)]
final class MakeJobCommand extends MakeClassCommand
{
    protected function nameHint(): string
    {
        return 'The job name, a verb, e.g. "SendWelcomeMail"';
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

        namespace App\\Jobs;

        use Hydra\\Queue\\Contracts\\JobInterface;

        /**
         * Load what it works on from the payload's ids rather than trusting the
         * push: by the time the worker gets to it the row may have changed, or gone.
         */
        final class {$class} implements JobInterface
        {
            /** Built by the container when the worker runs it, so ask for services here. */
            public function __construct() {}

            /**
             * @param array<string, mixed> \$payload what it was pushed with, back from JSON
             */
            public function handle(array \$payload): void
            {
                // A job that throws is retried, then moved to failed jobs. Return
                // quietly when there is nothing left to do, such as a row gone.
            }
        }

        PHP;
    }

    protected function afterCreate(OutputInterface $output, string $class): void
    {
        $output->note('Push it with the queue, from anywhere that has QueueInterface:');
        $output->write(<<<TXT
             \$this->queue->push({$class}::class, ['user' => \$user->id]);

         The payload is stored as JSON, so it carries ids and plain values, not
         objects: the job loads the rest when it runs.
        TXT);
    }
}
