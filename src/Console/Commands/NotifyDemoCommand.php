<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Entities\User;
use App\Repositories\UserRepository;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;

/**
 * Sends a user a sample notice, which lights the bell in every admin tab they
 * have open: the quickest check that notifications and the hub are up, and
 * the whole of sending one, in one call.
 */
#[AsCommand(
    name: 'notify:demo',
    description: 'Send a user a sample notice, which rings their bell',
)]
final class NotifyDemoCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Notifier $notifier,
    ) {}

    public function arguments(): array
    {
        return [Argument::required('username', 'Whose bell to ring')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $username = trim($input->argument('username'));
        $user = $this->users->byUsername($username);

        if (!$user instanceof User) {
            $output->error("No user is called \"{$username}\".");

            return ExitCode::Failure;
        }

        $this->notifier->notify($user->id, new Notice(
            'A notice from notify:demo',
            'If you can read this on the bell, notifications work end to end.',
            '/admin',
            'demo',
        ));

        $output->success("Notified {$username}. Their bell rings in every open admin tab.");

        return ExitCode::Success;
    }
}
