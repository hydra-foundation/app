<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Console\ArrayInput;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;

/**
 * Runs a command the way a terminal would, without one.
 *
 * The generators are the most-tested thing in the skeleton and every one of
 * their tests wants the same three lines — build the command, run it, look at
 * what it said. This is those three lines, plus the one translation worth
 * having: input is written the way it is typed (`['name' => 'post',
 * '--force' => true]`) rather than split into arguments, options and flags by
 * hand at forty call sites.
 */
final readonly class Console
{
    /**
     * @param array<string, string|bool> $input arguments by name, options and flags with their leading --
     * @param list<string> $answers replies to ask() and askHidden(), in order
     * @param list<bool> $confirmations replies to confirm(), in order
     */
    public static function run(
        CommandInterface $command,
        array $input = [],
        array $answers = [],
        array $confirmations = [],
    ): CommandRun {
        $arguments = [];
        $options = [];
        $flags = [];

        foreach ($input as $key => $value) {
            if (!str_starts_with($key, '--')) {
                $arguments[$key] = (string) $value;

                continue;
            }

            $name = substr($key, 2);

            // A bool is a flag, and false means "not typed" rather than
            // "typed as false" — there is no way to type the second.
            if (is_bool($value)) {
                if ($value) {
                    $flags[] = $name;
                }

                continue;
            }

            $options[$name] = $value;
        }

        $output = (new FakeOutput)->willAnswer($answers)->willConfirm($confirmations);

        return new CommandRun(
            $command->execute(ArrayInput::forCommand($command, $arguments, $options, $flags), $output),
            $output,
        );
    }
}
