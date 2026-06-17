<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\UserRepository;
use Hydra\Auth\Contracts\HasherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a user account from the console — the seeding path the auth demo used
 * to need a hand-run `password_hash` one-liner for.
 *
 * Constraints mirror the admin user form (username 3–64 of [A-Za-z0-9_] and
 * unique, password ≥ 8, role user|admin) so the two entry points agree on what a
 * valid account is. The password is taken ONLY through a hidden prompt, never as
 * an argument: keeping it out of argv and shell history is the same "credential
 * handling stays in one audited place" rule auth is built around. Hashing goes
 * through the bound HasherInterface, so the digest the command stores is exactly
 * the one the guard later verifies.
 */
#[AsCommand(
    name: 'make:user',
    description: 'Create a user account (prompts for the password)',
)]
final class MakeUserCommand extends Command
{
    private const ROLES = ['user', 'admin'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly HasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'The login username');
        $this->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'user or admin', 'user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $role = (string) $input->getOption('role');
        if (!in_array($role, self::ROLES, true)) {
            $io->error('Role must be user or admin.');
            return Command::FAILURE;
        }

        /** @var string|null $usernameArg */
        $usernameArg = $input->getArgument('username');

        // Interactive: ->ask re-prompts when the validator throws. From the
        // argument: validate once and fail cleanly (no re-prompt, no stack trace).
        if ($usernameArg === null) {
            $username = (string) $io->ask('Username', null, $this->checkUsername(...));
        } else {
            try {
                $username = $this->checkUsername(trim($usernameArg));
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $password = $io->askHidden('Password (min 8 characters)', $this->checkPassword(...));
        $confirm = $io->askHidden('Confirm password', $this->checkPassword(...));

        if ($password !== $confirm) {
            $io->error('Passwords do not match.');
            return Command::FAILURE;
        }

        $id = $this->users->create($username, $this->hasher->hash($password), $role);

        $io->success("Created {$role} '{$username}' (id {$id}).");

        return Command::SUCCESS;
    }

    /**
     * Validate the username against the same rules as the admin form, throwing
     * so SymfonyStyle re-prompts (interactive) or aborts (from the argument).
     */
    private function checkUsername(string $username): string
    {
        if (preg_match('/^[A-Za-z0-9_]{3,64}$/', $username) !== 1) {
            throw new \RuntimeException('Username must be 3–64 characters: letters, numbers and underscores only.');
        }

        if ($this->users->byUsername($username) !== null) {
            throw new \RuntimeException('That username is already taken.');
        }

        return $username;
    }

    private function checkPassword(?string $password): string
    {
        if ($password === null || strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }

        return $password;
    }
}
