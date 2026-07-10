# Hydra App

The skeleton every Hydra project starts from.

## Requirements

- **PHP 8.2+** and **Composer**
- **Docker** + Compose (for the full stack: PHP-FPM, nginx, MariaDB, Redis)

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

---

Everything else — commands, architecture, configuration, migrations, tests — lives in the wiki.
