# Hydra App

The skeleton every Hydra project starts from.

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
cp .env.example .env             # defaults run as-is for local dev
composer install                 # resolves the framework packages
php bin/console key:generate     # writes a fresh APP_KEY into .env
```

## Run

**With Docker (full stack):**

```bash
./bin/dev up -d --build          # PHP-FPM + nginx + MariaDB + Redis
```

Open **http://localhost:8080** (the port is `APP_PORT` in `.env`).

**Without Docker (public site only):**

```bash
composer start                   # php -S localhost:8000 -t public/
```

Open **http://localhost:8000**.

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
