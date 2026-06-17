<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates a 256-bit application key (64 hex chars) and writes it to APP_KEY
 * in the .env file — automating the `openssl rand -hex 32` the .env.example
 * documents.
 *
 * Overwriting an existing key is guarded behind --force: APP_KEY will seed
 * encryption/signing, so regenerating it invalidates anything sealed with the
 * old key. The guard makes that an explicit choice, never an accident.
 */
#[AsCommand(
    name: 'key:generate',
    description: 'Generate the application key and write it to .env',
)]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly string $envPath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite an existing APP_KEY (invalidates anything sealed with the old key)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_file($this->envPath)) {
            $io->error("No .env file at {$this->envPath}. Copy .env.example to .env first.");
            return Command::FAILURE;
        }

        $contents = file_get_contents($this->envPath);

        if ($this->currentKey($contents) !== '' && !$input->getOption('force')) {
            $io->error('APP_KEY is already set. Re-run with --force to overwrite it.');
            return Command::FAILURE;
        }

        $key = bin2hex(random_bytes(32));
        file_put_contents($this->envPath, $this->withKey($contents, $key));

        $io->success("Application key set: {$key}");

        return Command::SUCCESS;
    }

    /** The current APP_KEY value, or '' when unset/empty. */
    private function currentKey(string $contents): string
    {
        return preg_match('/^APP_KEY=(.*)$/m', $contents, $m) === 1 ? trim($m[1]) : '';
    }

    /** Replace the APP_KEY line in place, or append one if the file has none. */
    private function withKey(string $contents, string $key): string
    {
        if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
            return preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$key}", $contents, 1);
        }

        return rtrim($contents, "\n") . "\nAPP_KEY={$key}\n";
    }
}
