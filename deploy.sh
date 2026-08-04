#!/usr/bin/env bash
#
# HR & Attendance — deploy a new revision.
#
#   sudo -u www-data ./deploy/deploy.sh
#
# Safe to re-run. Assumes the first-time setup in Deployment-Guide_Production.md
# has already been done — this script updates an install, it does not create one.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hrms}"
PHP="${PHP:-/usr/bin/php}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

# Refuse to run against a non-production install. Guessing wrong here means
# running migrations against the wrong database.
if ! grep -qx 'APP_ENV=production' .env; then
    echo "Refusing to deploy: .env is not APP_ENV=production" >&2
    exit 1
fi

echo "==> Maintenance mode"
# --secret lets you check the deploy through the maintenance page before letting
# staff back in: visit https://your-host/deploying-now once and the browser is
# allowed through for the rest of the session.
$PHP artisan down --secret="deploying-now" --render="errors::503" || true

# Whatever happens below, the site comes back up.
trap '$PHP artisan up || true' EXIT

echo "==> Backup before migrating"
# The point of the backup is the migration. Taking it here rather than relying
# on last night's means a migration that goes wrong costs minutes, not a day of
# punches.
$PHP artisan db:backup --verify

echo "==> Fetching $BRANCH"
git fetch --all --prune
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "==> Dependencies"
# --no-dev keeps test and debug packages off the server; the optimised autoloader
# is a measurable difference on every request.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Migrating"
$PHP artisan migrate --force

echo "==> Rebuilding caches"
# Clear before caching: a stale config cache is the classic "I changed .env and
# nothing happened" deploy, and it hides changes to mail and FCM settings in
# particular.
$PHP artisan config:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

echo "==> Storage link"
$PHP artisan storage:link || true

echo "==> Restarting the queue worker"
# The running worker holds the code it booted with. Without this it keeps
# executing the previous revision until it retires on its own.
$PHP artisan queue:restart
sudo systemctl restart hrms-worker || echo "  (could not restart hrms-worker — do it by hand)"

echo "==> Preflight"
# Fails the deploy loudly rather than leaving a misconfigured site serving.
$PHP artisan hrms:preflight

echo "==> Done"
