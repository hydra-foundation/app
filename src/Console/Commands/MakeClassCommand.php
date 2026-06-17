<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared base for the class-emitting stub generators (make:controller,
 * make:ability).
 *
 * It owns the mechanics every such generator repeats — turn a loose name into a
 * PascalCase class (appending a fixed suffix like "Controller" if missing),
 * resolve the target file under a fixed source directory, refuse to clobber an
 * existing file without --force, and write the rendered stub. Subclasses supply
 * only the policy: the directory, the suffix, and the file body via {@see stub()}.
 *
 * Extracted once a second generator existed (controller + ability), not ahead of
 * one — the same restraint the rest of the framework follows. make:migration is
 * deliberately NOT a subclass: it emits a timestamped .sql file, not a class, so
 * folding it in here would be a forced fit.
 */
abstract class MakeClassCommand extends Command
{
    public function __construct(private readonly string $targetDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, $this->nameHint());
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $class = $this->className((string) $input->getArgument('name'));
        if ($class === '') {
            $io->error('Name must contain at least one letter or digit.');
            return Command::FAILURE;
        }

        $path = $this->targetDir . '/' . $class . '.php';

        if (is_file($path) && !$input->getOption('force')) {
            $io->error("{$class} already exists. Re-run with --force to overwrite it.");
            return Command::FAILURE;
        }

        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0o775, true) && !is_dir($this->targetDir)) {
            $io->error("Could not create directory {$this->targetDir}.");
            return Command::FAILURE;
        }

        file_put_contents($path, $this->stub($class));

        $io->success("Created {$class}");
        $this->afterCreate($io, $class);

        return Command::SUCCESS;
    }

    /** The argument description shown in help, e.g. 'The controller name, e.g. "post"'. */
    abstract protected function nameHint(): string;

    /** A class-name suffix the generator guarantees, e.g. 'Controller'; '' for none. */
    abstract protected function suffix(): string;

    /** The full file contents for the given class name. */
    abstract protected function stub(string $class): string;

    /** Hook for a post-create reminder (e.g. "register this in CONTROLLERS"). */
    protected function afterCreate(SymfonyStyle $io, string $class): void {}

    /**
     * Normalise a loose name into a PascalCase class with the guaranteed suffix:
     * "blog post" / "blog-post" / "BlogPost" all become "BlogPost", and a
     * make:controller "post" becomes "PostController" (suffix added once).
     */
    private function className(string $name): string
    {
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? '';
        $class = str_replace(' ', '', ucwords(trim($words)));

        if ($class === '') {
            return '';
        }

        $suffix = $this->suffix();
        if ($suffix !== '' && !str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        return $class;
    }
}
