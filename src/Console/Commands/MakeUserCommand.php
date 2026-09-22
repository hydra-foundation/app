<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Entities\Role;
use App\Repositories\UserRepository;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;

/**
 * Creates an account from the terminal, which is how the first admin comes to
 * exist on a fresh install: there is no signup, and nobody can sign in to make
 * one. The password is prompted for rather than taken as an argument, so it
 * stays out of the shell history and the process list.
 */
#[AsCommand(
    name: 'make:user',
    description: 'Create a user account (prompts for the password)',
)]
final class MakeUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly HasherInterface $hasher,
    ) {}

    public function arguments(): array
    {
        return [Argument::optional('username', 'The login username')];
    }

    public function options(): array
    {
        return [
            Option::value(
                'role',
                'r',
                'One of: ' . implode(', ', Role::values()),
                Role::DEFAULT->value,
            ),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {

        $role = Role::tryFrom($input->option('role'));
        if ($role === null) {
            $output->error('Role must be one of: ' . implode(', ', Role::values()) . '.');
            return ExitCode::Failure;
        }

        $typed = $input->hasArgument('username');

        // Interactive: ->ask re-prompts when the validator throws. From the
        // argument: validate once and fail cleanly (no re-prompt, no stack trace).
        if (!$typed) {
            $username = (string) $output->ask('Username', null, $this->checkUsername(...));
        } else {
            try {
                $username = $this->checkUsername(trim($input->argument('username')));
            } catch (\RuntimeException $e) {
                $output->error($e->getMessage());
                return ExitCode::Failure;
            }
        }

        $password = $output->askHidden('Password (min 8 characters)', $this->checkPassword(...));
        $confirm = $output->askHidden('Confirm password', $this->checkPassword(...));

        if ($password !== $confirm) {
            $output->error('Passwords do not match.');
            return ExitCode::Failure;
        }

        $id = $this->users->create($username, $this->hasher->hash($password), $role);

        $output->success("Created {$role->value} '{$username}' (id {$id}).");

        return ExitCode::Success;
    }

    /**
     * Validate the username against the same rules as the admin form, throwing
     * so the output re-prompts (interactive) or aborts (from the argument).
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
