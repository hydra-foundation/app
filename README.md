# Hydra App

The skeleton every [Hydra PHP framework](https://hydra.williamhleucka.com) project starts from.
Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

## Status

> **Experimental.** This is a personal project, built in the open for my own use.

- Breaking changes are expected, between any two versions and without notice.
- Anyone curious enough to use it is doing so at their own risk.

## Requirements

- **PHP 8.2+** and **Composer**
- **Docker** + Compose (for the full stack: PHP-FPM, nginx, MariaDB, Redis)

`composer.json` pins `config.platform.php` to the oldest version above, so the
committed lock installs on every version the skeleton claims rather than only on
the one it happened to be generated with.

## Setup

```bash
cp .env.example .env             # runs as-is; set APP_DEBUG=true for local dev
composer install                 # resolves the framework packages
php bin/console key:generate     # writes a fresh APP_KEY into .env
```

The defaults are the safe ones, not the convenient ones: `APP_DEBUG=false`, and
the Docker base stack builds with `prod.ini`. `./bin/dev` layers the development
overrides on top, so debugging is something you ask for rather than something
you remember to turn off.

## Run

**With Docker (full stack):**

```bash
./bin/dev up -d --build          # PHP-FPM + nginx + MariaDB + Redis
```

Open **http://localhost:8080** (the port is `APP_PORT` in `.env`).

`./bin/dev` also opens `storage/logs` to every user (mode 1777): PHP-FPM writes
the log as www-data and the scheduler as root. Under the prod compose files, do
the same once with `chmod 1777 storage/logs`.

**Without Docker (public site only):**

```bash
composer start                   # php -S localhost:8000 -t public/
```

Open **http://localhost:8000**.

## The console

```bash
./hydra                          # the command list
./hydra admin:check
./hydra make:source invoice --writable
```

`./hydra` is `./bin/exec bin/console` — the console inside the php container,
which is where the database and Redis are reachable. `php bin/console` works
directly too, on a machine with the extensions installed.

## Checks

```bash
composer qa                      # static analysis, style, tests
```

The three also run on their own as `composer stan`, `composer lint` and
`composer test`; `composer lint:fix` applies the style fixes instead of
reporting them. CI runs the same tools on PHP 8.2 through 8.5, and once more
against the framework's `main` branch, so a change upstream that breaks the
skeleton fails before it is released rather than after.

---

Everything else (commands, architecture, configuration, migrations, tests) lives in the wiki (coming soon?)
