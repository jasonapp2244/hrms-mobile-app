# KEMP — Backend

Laravel 12 + MySQL. The API the mobile app talks to, and the web dashboard for
administrators, HR and line managers.

The Flutter client lives in a separate repository, **`hr-mobile`**.

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan emp:install          # creates one administrator, prompted

php artisan serve                # http://127.0.0.1:8000
php artisan test                 # 1561 tests, ~300s, SQLite in memory
```

MySQL must be started **from the XAMPP Control Panel** on the dev machine —
launching `mysqld.exe` as a background task does not persist, it exits.

**A plain `php artisan db:seed` creates no users**, deliberately, so that seeding
a real installation cannot conjure an account with a known password. For the
demo company and its seven accounts:

```bash
php artisan db:seed --class='Database\Seeders\DemoDataSeeder'
```

Quote that class name — unquoted, bash eats the backslashes.

## What is in here

| | |
|---|---|
| `app/`, `routes/`, `resources/` | The application. `routes/api.php` is the app's contract, `routes/web.php` the dashboard |
| `tests/` | 1561 tests. `ApiDocsTest` walks the route table and fails the build on an undocumented endpoint |
| `deploy/` | `deploy.sh`, nginx config, the systemd unit and the cron lines |
| `CLAUDE.md` | **Read this first.** The traps that have already cost time, the four roles, and how to deploy |
| `API-Reference_v1.md` | The API contract, and **authoritative**. `hr-mobile` carries a stamped copy |
| `Feature-List_Backend.md` | What is built across Parts A, C and D |
| `Deployment-Guide_Production.md` | The runbook |

## Deploying

The app is live at `https://hrams.devonlinetestserver.com` — permanent, not a
staging step. Managed webspace, so the queue and scheduler are cron-driven.

```bash
cd /home/devonlinetestserver-hrams/htdocs/hrams.devonlinetestserver.com
ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh
```

**The flag is required and is not a workaround** — that box's `.env` says
`APP_ENV=staging` and `deploy.sh` refuses to guess which database to migrate.
`php artisan emp:preflight` gates the deploy; `CLAUDE.md` explains what it
checks and which of its findings are deliberate.

## The boundary with the app

`API-Reference_v1.md` is authoritative here and checked on every test run.
Changing anything the app can see — a route, an error code, a notification
route, a capability in the `can` block on `/auth/me` — needs a matching change
in `hr-mobile`. `CLAUDE.md` has the four rules in full.
