# Hydra App

The skeleton application — the composition root that wires the packages together
and the home for everything that is application policy rather than framework
mechanism. This is where the unbound contracts get fulfilled: the user provider,
the database connection, the view layer, the abilities.

## What the app fulfils

The capability packages leave exactly the contracts they cannot know to the app,
and `AppServiceProvider` binds them at the composition root:

- **`Hydra\Auth\Contracts\UserProviderInterface`** → `UserRepository`, and the
  user entity implements `AuthenticatableInterface`.
- **`Hydra\Http\Contracts\ServerRequestProviderInterface`** → `NyholmRequestProvider`.
- **`Hydra\Authorization\Contracts\AbilityInterface`** → app ability classes
  (e.g. `AccessAdmin`), resolved through the container.
- The concrete **container**, **PSR-7/-17 implementations**, **database
  connection**, **view layer**, and **logger** — all application choices.

## Data

Repositories are hand-written over a thin `ConnectionInterface` (a PDO seam) and
return typed entities — no ORM. The app connects to MariaDB; the test suite runs
the same repositories against SQLite through the same seam.

## Migrations

Migrations are plain `.sql` files in `database/migrations` — the data-layer
equivalent of the hand-written repositories: no ORM, no fluent builder, just SQL.
Each file is a **forward-only** change; there are no down migrations by design.
Files apply in lexical order, so the `{Ymd_His}_name.sql` timestamp prefix
doubles as the ordering key, and a `migrations` table records which have run so
re-applying is a no-op.

`MigrationRunner` takes the raw PDO (not the prepared-only `ConnectionInterface`
seam) because DDL is multi-statement and unparameterised — it runs through
`PDO::exec`. One caveat: MariaDB has no transactional DDL, so a migration that
fails halfway leaves partial state. Keep each migration to one logical change.

```
php bin/console make:migration "create posts table"  # scaffold an empty timestamped .sql
php bin/console migrate:run                           # apply all pending migrations
php bin/console migrate:status                        # list applied vs pending
php bin/console migrate:fresh                         # drop all tables and re-run (dev only)
```

`migrate:fresh` is destructive: it refuses when `APP_DEBUG` is off unless given
`--force`, and otherwise asks to confirm. The `migrate:*` commands are the only
ones loaded lazily — they open the database connection only when actually run, so
`key:generate` stays usable before the database exists.

## Views

`PhpView` is native-PHP templating behind a `ViewInterface` seam: escape-by-
default, with Twig-style `extends`/`sections` via `Template`. The `Htmx` helpers
negotiate full-page versus fragment responses so a controller renders one view
and returns the right slice for the request.

## Middleware

The global middleware stack is declared in `AppServiceProvider::MIDDLEWARE`
(outermost first) and resolved through the container, so each layer gets full
dependency injection. The app-owned defaults exist to apply *application policy*
the framework deliberately leaves open:

- **`RequestLoggingMiddleware`** — one PSR-3 access line per request (method,
  path, status, duration). Outermost, so it always logs a final status.
- **`SecurityHeadersMiddleware`** — stamps `X-Content-Type-Options`,
  `X-Frame-Options`, and `Referrer-Policy` on every response. This is the single,
  server-agnostic source of truth for those headers — do **not** also set them at
  the proxy (the nginx dev conf intentionally does not), or each is duplicated.
- **`ForceHttpsMiddleware`** — redirects insecure requests to `https` (301) and
  emits HSTS on secure ones. Gated on `FORCE_HTTPS`, off by default so local
  http dev is untouched.
- **`RedirectUnauthenticatedMiddleware`** — maps auth's 401 to a redirect to
  `/login`. Its mirror, **`RedirectAuthenticatedMiddleware`**, is a per-route
  guard that keeps a signed-in visitor off the guest routes.

> **`ForceHttpsMiddleware` and proxies.** It treats a request as secure when the
> URI scheme is `https` *or* the `X-Forwarded-Proto` header is `https`, and it
> trusts that header **unconditionally**. That is correct behind a trusted,
> TLS-terminating proxy (Traefik, nginx) — the deployment Hydra assumes — but if
> you ever run the app *directly* internet-facing with `FORCE_HTTPS` on, a client
> could spoof `X-Forwarded-Proto: https` to skip the upgrade. The impact is low
> (a client only downgrades its own connection; spoofed HSTS over http is ignored
> by browsers, and no auth or cookie decision keys off this), so it is left as-is
> until a no-proxy deployment is a real target — at which point the fix is to
> trust the forwarded header only from known proxy addresses.

## Console

`bin/console` is the CLI, built on Symfony Console. It shares the HTTP front
controller's composition root — `App\Bootstrap::application()` builds the
container and registers the same providers, so commands resolve the exact
bindings the app runs with (the console just doesn't run the HTTP kernel).
Commands live in `src/Console/Commands` and extend Symfony's `Command` directly
— no Hydra seam, because the console engine is never swapped.

```
php bin/console key:generate        # write a fresh APP_KEY to .env (--force to replace)
php bin/console route:cache         # compile controller routes to the cache
php bin/console route:cache:clear   # drop the compiled route cache
php bin/console make:user [name]    # create a user account (prompts for the password)
php bin/console make:controller <n> # scaffold App\Controllers\<n>Controller
php bin/console make:ability <name>  # scaffold an App\Authorization ability (denies by default)
```

The `make:*` stub generators emit a file matching the existing conventions and
refuse to overwrite without `--force`. `make:controller` derives a starter route
from the name and prints a reminder to register the class in
`AppServiceProvider::CONTROLLERS` — it deliberately does NOT rewrite that list,
since reading the whole route contract in one place is the point of keeping it
explicit. `make:ability` stubs the app's own policy "noun" (which
hydra/authorization never ships) and **denies by default**, so a half-written
rule fails closed; abilities need no registration (referenced by `::class` at the
use site).

`route:cache` is the sole writer of the route cache; the web path only reads it
(see `AppServiceProvider::compileRoutes`). Enable the cache with `ROUTE_CACHE` in
production and build it at deploy time.

`make:user` seeds an account (the auth demo's first login). It enforces the same
rules as the admin user form — username 3–64 of `[A-Za-z0-9_]` and unique,
password ≥ 8, `--role user|admin` — and takes the password only through a hidden
prompt, never as an argument, so it never lands in shell history or the process
list. Hashing goes through the bound `HasherInterface`, so the stored digest is
exactly what the guard later verifies. See the migrate commands below for the
lazy-loading note these DB-backed commands share.
