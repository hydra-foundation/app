# Hydra App

The skeleton application — the starting point every Hydra project is created
from. It's the composition root that wires the framework packages together and
the home for everything that is *application policy* rather than *framework
mechanism*: the user provider, the database connection, the view layer, the
abilities.

Out of the box it boots, serves one page (`/` → "Welcome to Hydra"), and ships a
working auth/admin slice you can build on or delete.

## Requirements

- **PHP 8.2+** and **Composer** (for the no-Docker path).
- **Docker** + Compose (for the full stack: PHP-FPM, nginx, MariaDB, Redis).

Hydra is developed as a monorepo: this app lives in `app/`, and the framework
packages (`hydra/core`, `hydra/http`, …) are sibling directories symlinked in
through Composer `path` repositories. So `app/` expects `../core`, `../http`,
etc. to exist beside it.

## Quick start

```bash
cp .env.example .env                 # the defaults run as-is for local dev
composer install                     # resolves the symlinked framework packages
php bin/console key:generate         # writes a fresh APP_KEY into .env
```

Then bring it up one of two ways.

**With Docker (full stack — recommended):**

```bash
./bin/dev up -d --build              # PHP-FPM + nginx + MariaDB + Redis
```

Open **http://localhost:8080** (the port is `APP_PORT` in `.env`). You should see
**Welcome to Hydra**. That's the whole loop working: nginx → PHP-FPM →
`public/index.php` → `HomeController` → the `home` view.

> Inside Docker the repo root is mounted at `/var/www/html` (the symlinks need
> it), so run Composer and PHPUnit *in the container* — `./bin/app-container
> composer install`, `./bin/phpunit`. The host commands above also work as long
> as the sibling packages are present on the host.

**Without Docker (the public site only — no DB needed for hello world):**

```bash
composer start                       # php -S localhost:8000 -t public/
```

Open **http://localhost:8000**.

## Updating

```bash
git pull
composer install                     # or: ./bin/app-container composer install
php bin/console migrate:run          # apply any new migrations (DB only)
```

When the framework packages change next door, `composer install` picks them up
through the symlinks — there's nothing to publish or version locally.

## Everyday commands

```bash
./bin/dev up -d            # start the dev stack       ./bin/dev down       # stop it
./bin/dev logs -f nginx    # tail a service            ./bin/app-container  # shell in the php container
./bin/phpunit              # run the test suite        ./bin/db             # open a MariaDB shell

php bin/console            # list everything the console can do
php bin/console make:user  # create a login (prompts for the password)
php bin/console make:controller Posts   # scaffold App\Controllers\PostsController
```

`make:*` generators match the existing conventions and refuse to overwrite
without `--force`. `make:controller` prints a reminder to register the class in
`AppServiceProvider::CONTROLLERS` — that list is the one place the whole route
contract is read, so it's edited by hand on purpose.

## How it's put together

A request enters at `public/index.php`, which calls `App\Bootstrap::application()`
to build the container, register `AppServiceProvider`, and run the HTTP kernel.
`bin/console` builds the *same* composition root, so commands resolve the exact
bindings the web app runs with.

**What the app fulfils.** The framework packages leave the contracts they can't
know to the app, and `AppServiceProvider` binds them at the composition root —
the user provider (`UserRepository`), the PSR-7 request provider, the abilities
(e.g. `AccessAdmin`), and the concrete container, database connection, view
layer, and logger. All of these are *application* choices.

**Data.** Repositories are hand-written over a thin `ConnectionInterface` (a PDO
seam) and return typed entities — no ORM. The app talks to MariaDB; the test
suite runs the same repositories against SQLite through the same seam.

**Migrations** are plain, forward-only `.sql` files in `database/migrations`,
applied in lexical order (the `{Ymd_His}_name.sql` prefix is the ordering key).
A `migrations` table records what has run, so re-applying is a no-op.

```bash
php bin/console make:migration "create posts table"   # scaffold an empty timestamped .sql
php bin/console migrate:run                            # apply all pending
php bin/console migrate:status                         # applied vs pending
php bin/console migrate:fresh                          # drop all + re-run (dev only; needs --force when debug is off)
```

**Views.** `PhpView` is native-PHP templating behind a `ViewInterface` seam:
escape-by-default, with Twig-style `extends`/`sections` via `Template`. The
`Htmx` helpers negotiate full-page vs. fragment responses.

**Middleware.** The global stack is declared in `AppServiceProvider::MIDDLEWARE`
(outermost first) and resolved through the container, so each layer gets full
DI. The defaults apply application policy the framework leaves open: request
logging, security headers (the single server-agnostic source of truth — the
nginx conf deliberately does *not* set them too), optional HTTPS forcing
(`FORCE_HTTPS`), and the unauthenticated → `/login` redirect.

## Configuration

Everything is environment-driven via `.env` (see `.env.example` for the full,
commented list). The defaults are tuned for local dev; the ones that matter most:

| Variable | What it does |
| --- | --- |
| `APP_KEY` | Signing key. Generate with `key:generate` before first boot. |
| `APP_DEBUG` | `true` locally; **set `false` in production**. |
| `APP_PORT` | Host port the dev stack publishes nginx on (`8080`). |
| `ROUTE_CACHE` | Compile routes to a cache. Off in dev; on in prod via `route:cache`. |
| `FORCE_HTTPS` | Redirect to https + HSTS. Off by default so local http works. |
| `DB_*` / `REDIS_*` | MariaDB and Redis connection settings. |

## Tests

```bash
./bin/phpunit                          # whole suite, in the container
./bin/phpunit --filter SomeTest        # a subset
./bin/phpunit --testdox                # readable output
```

## Going to production

Use the prod stack wrapper, which layers `docker-compose.prod.yml` over the base
(prod PHP ini, restart policies, TLS via Traefik):

```bash
./bin/prod up -d --build
```

In production set `APP_DEBUG=false`, a real `APP_KEY`, `FORCE_HTTPS=true`,
`ROUTE_CACHE=true` (and build it at deploy time with `php bin/console
route:cache`), and real `DB_*` / `REDIS_*` credentials.
