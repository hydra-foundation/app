# AGENTS.md

How to work in a Hydra application, for coding agents and the people working
beside them. The wiki is the long form: https://hydra.williamhleucka.com/docs/.
Every command and path in backticks below is checked by
`tests/Unit/AgentsGuideTest.php`, so if one is wrong, that test says so.

## Commands

```bash
cp .env.example .env              # once; runs as-is
composer install
php bin/console key:generate      # writes APP_KEY into .env
./bin/dev up -d --build           # PHP-FPM, nginx, MariaDB, Redis, the SSE hub
./hydra migrate:run               # ./hydra is bin/console inside the php container
```

- `composer qa` is the gate: `composer stan`, `composer lint` and
  `composer test` in that order. `composer lint:fix` applies the style fixes.
- CI runs the PHP tests in random order: `vendor/bin/phpunit --order-by=random`.
  A test that passes alone and fails shuffled leaks state; fix the leak.
- The browser scripts have node tests: `node --test "tests/js/**/*.test.mjs"`.
- `./hydra` with no arguments lists every command.

## Where things go

| Path | What lives there |
|---|---|
| `src/Controllers` | HTTP controllers, routed by `#[Route]` attributes |
| `src/Providers/AppServiceProvider.php` | the registration point: `CONTROLLERS`, `MODULES`, `MIDDLEWARE`, listeners in `boot()` |
| `src/Admin` | admin modules, sources, actions, widgets, presenters |
| `src/Repositories` | every SQL statement the app runs |
| `src/Entities` | typed rows the repositories return |
| `src/Authorization` | abilities, one class per thing someone may do |
| `src/Jobs` | queued jobs |
| `src/Listeners` | event listeners |
| `src/Tasks` | scheduled tasks |
| `src/Mail` | messages |
| `src/Config` | typed configuration read from `.env` |
| `views` | PHP templates |
| `database/migrations` | forward-only SQL migrations |
| `tests/Unit`, `tests/Integration` | PHPUnit; integration tests boot the app on in-memory sqlite |
| `tests/Support/TestSchema.php` | the sqlite mirror of the migrations |
| `public/js` | the browser scripts, `public/js/app.js` among them |

## One way to do each thing

Start from the generator; it writes the house style. Then follow the page.

| Task | Start with | Then |
|---|---|---|
| A table | `./hydra make:migration create_invoices` | mirror it in `tests/Support/TestSchema.php` |
| An admin screen over a table | `./hydra make:admin invoices` | register in `MODULES`; docs/module.html |
| A page or endpoint | `./hydra make:controller invoice` | register in `CONTROLLERS`; docs/routing.html |
| Something that happens after an event | `./hydra make:listener AuditWrites` | register in `boot()` as a closure; docs/events.html |
| Work that should not hold up a request | `./hydra make:job SendInvoice` | push ids, not objects; docs/queue.html |
| Who may do what | `./hydra make:ability ManageInvoices` | docs/auth.html |
| Take an upload | `PublicStorageInterface` or `StorageInterface` (private) | docs/files.html |
| Limit one endpoint | a middleware with its own `RateLimitPolicy` | docs/rate-limits.html |
| Keep an expensive value a while | `StoreInterface`: `get`, `put` with a TTL, `forget` on write | docs/cache.html |
| A message after a redirect | `SessionInterface::flash()` / `flashed()` | docs/sessions.html |
| A list that updates itself | `ModuleChanges::publish('invoices', $id)` | publish after the write commits; docs/live.html |

The generators that read a table (`make:source`, `make:module`, `make:entity`,
`make:repository`, `make:source-test`) need the database: run them through
`./hydra`.

## Conventions

- **SQL is what you read.** Repositories write plain SQL with bound
  parameters through `ConnectionInterface`. There is no query builder and no
  ORM; do not add one.
- **Escape everything** in templates with `$this->e()`. Raw output is a
  decision, written down beside it.
- **Errors say what to do.** A misconfiguration names the fix, not only the
  fault.
- **Comments carry the reason**, not a summary of the next line.
- **A module's slug** is lowercase a-z, 0-9, `-` and `_`: it is a URL and a
  broadcast topic.
- **Tests come with the change.** A new source gets its contract test
  (`make:source-test`); a new table its `TestSchema` entry.

## Boundaries

- **Always:** run `composer qa` before calling a change done; keep
  `.env.example` in step with any new setting, commented.
- **Ask first:** adding a Composer or npm dependency; changing a migration
  that has run anywhere (write a new one instead); changing `docker/`, CI, or
  the security headers and CSP.
- **Never:** commit `.env` or a secret; edit `vendor/`; log a token, a
  password or a session id; disable CSRF or escaping to make something work.

## Docs

- Start: https://hydra.williamhleucka.com/docs/index.html
- Install and run: https://hydra.williamhleucka.com/docs/install.html
- Routing, requests, errors: https://hydra.williamhleucka.com/docs/routing.html
- Templates: https://hydra.williamhleucka.com/docs/templates.html
- Validation: https://hydra.williamhleucka.com/docs/validation.html
- Database and migrations: https://hydra.williamhleucka.com/docs/database.html
- Files and uploads: https://hydra.williamhleucka.com/docs/files.html
- Sessions: https://hydra.williamhleucka.com/docs/sessions.html
- Cache: https://hydra.williamhleucka.com/docs/cache.html
- Rate limits: https://hydra.williamhleucka.com/docs/rate-limits.html
- Events and listeners: https://hydra.williamhleucka.com/docs/events.html
- Logging: https://hydra.williamhleucka.com/docs/logging.html
- A module, step by step: https://hydra.williamhleucka.com/docs/module.html
- The admin: https://hydra.williamhleucka.com/docs/admin.html, its sources
  (https://hydra.williamhleucka.com/docs/sources.html) and dashboards
  (https://hydra.williamhleucka.com/docs/dashboards.html)
- Auth: https://hydra.williamhleucka.com/docs/auth.html
- Live updates: https://hydra.williamhleucka.com/docs/live.html
- Mail, queue, scheduler: https://hydra.williamhleucka.com/docs/mail.html,
  https://hydra.williamhleucka.com/docs/queue.html,
  https://hydra.williamhleucka.com/docs/scheduler.html
- Every console command: https://hydra.williamhleucka.com/docs/console.html
- Why it is built this way: https://hydra.williamhleucka.com/docs/ethos.html
