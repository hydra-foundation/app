<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Widgets\Status;
use Hydra\Database\MigrationRunner;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Whether the schema caught up with the code: what to look at after a deploy.
 * A pending migration is amber rather than red, because the site may well be
 * working; it is the next screen to touch the new column that will not be.
 *
 * Read-only on purpose. Running a migration from a web request, on the live
 * database, with no console to read, is how a deploy becomes an outage; the
 * card says what to run instead.
 */
final class MigrationsWidget implements PresenterInterface
{
    /** Named in the note before the rest are only counted. */
    private const NAMED = 5;

    public function __construct(
        private readonly MigrationRunner $migrations,
        private readonly ClockInterface $clock,
    ) {}

    public function present(): array
    {
        try {
            $summary = $this->migrations->summary();
        } catch (Throwable $e) {
            return [
                'status' => Status::Down,
                'headline' => 'No answer',
                'caption' => 'could not read the migrations table',
                'rows' => [],
                'note' => $e->getMessage(),
            ];
        }

        $rows = [['label' => 'Applied', 'value' => (string) count($summary->applied)]];

        if ($summary->last !== null) {
            $rows[] = ['label' => 'Last', 'value' => $summary->last];
        }

        $pending = count($summary->pending);

        if ($pending > 0) {
            $named = implode(', ', array_slice($summary->pending, 0, self::NAMED));

            return [
                'status' => Status::Warning,
                'headline' => "{$pending} pending",
                'caption' => 'run ./hydra migrate:run',
                'rows' => $rows,
                'note' => $pending > self::NAMED ? $named . ' and ' . ($pending - self::NAMED) . ' more' : $named,
            ];
        }

        return [
            'status' => Status::Ok,
            'headline' => 'Up to date',
            'caption' => $summary->lastAt === null
                ? 'no migrations'
                : 'last run ' . Readable::duration(max(0, $this->clock->now()->getTimestamp() - $summary->lastAt->getTimestamp())) . ' ago',
            'rows' => $rows,
            'note' => null,
        ];
    }
}
