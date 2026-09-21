<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Entities\Audit;
use App\Entities\User;
use App\Repositories\AuditRepository;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Events\Exported;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Auth\Contracts\GuardInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns the admin's row events into audit rows. The counterpart of
 * {@see \Hydra\Admin\LogAdminEventsListener}: that one writes a line to the log,
 * this one writes a row a person can read in the backend.
 *
 * The event carries no user, by design — the admin package does not depend on
 * authentication — so the guard is asked here, inside the request that raised it.
 */
final class AuditAdminEventsListener
{
    /** The row id an export is filed under, having no row of its own to name. */
    private const EXPORT = 'export';

    /**
     * The fields each module may have recorded, by module slug.
     *
     * An allowlist rather than a list of secrets to skip, because the two fail
     * in opposite directions. A module's fields are whatever the application
     * declared — UsersModule declares a password input — and a listener that
     * took values wholesale would copy a password into the audit table the first
     * time somebody set one. A field missing from here is recorded as a change
     * with no value, which is a worse record; a secret missing from a blocklist
     * would be a leak.
     */
    private const AUDITED = [
        'users' => ['username', 'role'],
    ];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly GuardInterface $guard,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AdminEvent $event): void
    {
        // Swallowed the way RecordActivityMiddleware swallows its own write. The
        // event is announced after the source took the row, so throwing here
        // would show the reader a 500 for a change that already happened and
        // still leave no audit row behind.
        try {
            $this->record($event);
        } catch (Throwable $e) {
            $this->logger->warning('Could not record audit: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function record(AdminEvent $event): void
    {
        if ($event instanceof Exported) {
            $this->write($event, self::EXPORT, null, $this->exported($event));

            return;
        }

        if ($event instanceof RowUpdated && $this->changed($event) === []) {
            return;
        }

        if ($event instanceof RowCreated || $event instanceof RowUpdated || $event instanceof RowDeleted) {
            $this->write($event, $event->id, $this->before($event), $this->after($event));
        }
    }

    private function write(AdminEvent $event, string $rowId, ?string $old, ?string $new): void
    {
        $user = $this->guard->user();

        $this->audit->record(new Audit(
            $event->module,
            $rowId,
            $old,
            $new,
            $user === null ? null : (int) $user->getAuthIdentifier(),
            $user instanceof User ? $user->username : null,
            $this->message($event),
        ));
    }

    /**
     * What left, and the view it left as: the criteria as a query string, which
     * is both how they already describe themselves and something a reader can
     * paste back into the admin to see exactly what was taken.
     */
    private function exported(Exported $event): string
    {
        return json_encode(
            ['rows' => $event->rows, 'view' => http_build_query($event->criteria->toQuery())],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function before(RowCreated|RowUpdated|RowDeleted $event): ?string
    {
        // A created row had no before. A rewrite records only what moved; a
        // delete records everything the row held, because nothing of it survives
        // anywhere else.
        return match (true) {
            $event instanceof RowUpdated => $this->values($event->module, $event->before, $this->changed($event)),
            $event instanceof RowDeleted => $this->values($event->module, $event->before),
            default => null,
        };
    }

    private function after(RowCreated|RowUpdated|RowDeleted $event): ?string
    {
        return match (true) {
            $event instanceof RowCreated => $this->values($event->module, $event->values),
            $event instanceof RowUpdated => $this->values($event->module, $event->values, $this->changed($event)),
            default => null,
        };
    }

    /**
     * The auditable fields of one row, as JSON, or null when none survived.
     *
     * @param array<string, mixed> $values
     * @param list<string>|null $only the fields the write actually moved
     */
    private function values(string $module, array $values, ?array $only = null): ?string
    {
        $kept = [];

        foreach (self::AUDITED[$module] ?? [] as $field) {
            if ($only !== null && !in_array($field, $only, true)) {
                continue;
            }

            if (array_key_exists($field, $values)) {
                $kept[$field] = $values[$field];
            }
        }

        return $kept === []
            ? null
            : json_encode($kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The event's own stable key, and for a rewrite the names of the fields that
     * moved. Names, never values: this is the line that says a password was
     * changed without being the place it was written down.
     */
    private function message(AdminEvent $event): string
    {
        if ($event instanceof Exported) {
            return sprintf('%s: %d rows', $event->action(), $event->rows);
        }

        if (!$event instanceof RowUpdated) {
            return $event->action();
        }

        return $event->action() . ': ' . implode(', ', $this->changed($event));
    }

    /**
     * The fields the write moved, less the write-only ones it left alone.
     *
     * A field the source does not read back — a password — is absent from the
     * row the event carries as `before`, so {@see RowUpdated::changed()} has
     * nothing to compare it against and reports it changed every time. The rule
     * applied here is the form's own: blank means leave it as it was. Without
     * this, every edit of a user would be recorded as a password change, and a
     * log that says a password changed whenever anything did says nothing about
     * passwords at all.
     *
     * @return list<string>
     */
    private function changed(RowUpdated $event): array
    {
        return array_values(array_filter(
            $event->changed(),
            static fn (string $field): bool => array_key_exists($field, $event->before)
                || ($event->values[$field] ?? '') !== '',
        ));
    }
}
