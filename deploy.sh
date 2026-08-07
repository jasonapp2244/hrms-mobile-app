#!/usr/bin/env bash
#
# HR & Attendance — deploy a new revision.
#
#   sudo bash deploy/deploy.sh
#
# Safe to re-run, and safe to interrupt: the site comes back up whatever
# happens, and nothing touches the database until the new code is on disk and
# verified. Assumes the first-time setup in Deployment-Guide_Production.md has
# already been done — this updates an install, it does not create one.
#
# Overridable:
#   OWNER=web-user            who the files belong to (default: owner of the repo)
#   BRANCH=main               branch to deploy
#   PHP=/usr/bin/php8.3       a specific PHP binary
#   ALLOW_NON_PRODUCTION=1    permit a staging box that is not APP_ENV=production

set -euo pipefail

PHP="${PHP:-php}"
BRANCH="${BRANCH:-main}"

# ---------------------------------------------------------------------------
# Where things are
#
# The repository root and the Laravel application are not always the same
# directory. On the documented layout they are; on a panel host the site lives
# at <domain>/ and the application at <domain>/hrms, because the web root has
# to point at hrms/public. Detected rather than configured, so the same command
# works on both.
# ---------------------------------------------------------------------------
SITE="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [ -z "$SITE" ]; then
    echo "Not inside a git checkout — run this from the repository." >&2
    exit 1
fi

if   [ -f "$SITE/artisan" ];      then APP="$SITE"
elif [ -f "$SITE/hrms/artisan" ]; then APP="$SITE/hrms"
else
    echo "Cannot find artisan under $SITE — is this the right repository?" >&2
    exit 1
fi

# Deploying as root leaves root-owned cache and log files that PHP-FPM then
# cannot write, which surfaces as a 500 with nothing useful in the log. The
# files are handed back at the end; this is who to hand them to.
OWNER="${OWNER:-$(stat -c '%U' "$SITE")}"

cd "$APP"

APP_ENV_VALUE="$(grep -E '^APP_ENV=' .env | head -1 | cut -d= -f2- | tr -d '"'\''' || true)"

if [ "$APP_ENV_VALUE" != "production" ] && [ "${ALLOW_NON_PRODUCTION:-0}" != "1" ]; then
    cat >&2 <<MSG
Refusing to deploy: APP_ENV is '${APP_ENV_VALUE:-unset}', not 'production'.

Guessing wrong here means running migrations against the wrong database. If
this really is a staging or demo box, say so explicitly:

    sudo ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh
MSG
    exit 1
fi

echo "==> Deploying $BRANCH"
echo "    repo:  $SITE"
echo "    app:   $APP"
echo "    owner: $OWNER"
echo "    env:   ${APP_ENV_VALUE:-unset}"

# ---------------------------------------------------------------------------
echo "==> Maintenance mode"
# --secret lets you check the deploy through the maintenance page before letting
# staff back in: visit https://your-host/deploying-now once and the browser is
# allowed through for the rest of the session.
$PHP artisan down --secret="deploying-now" --render="errors::503" || true

# Whatever happens below, the site comes back up.
trap '$PHP artisan up || true' EXIT

# ---------------------------------------------------------------------------
echo "==> Backup"
# Taken before the migration, not relied upon from last night: a migration that
# goes wrong then costs minutes rather than a day of punches.
#
# db:backup did not exist before this release, so a first update from an older
# revision has to fall back to mysqldump — which is exactly the deploy where a
# backup matters most.
BACKUP_DIR="${BACKUP_DIR:-$APP/storage/backups}"
mkdir -p "$BACKUP_DIR"

if $PHP artisan list --raw 2>/dev/null | grep -q '^db:backup'; then
    $PHP artisan db:backup --verify
else
    echo "    db:backup not in this revision yet — using mysqldump"
    envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'\'''; }
    DUMP="$BACKUP_DIR/pre-deploy-$(date +%F-%H%M).sql"
    mysqldump -h"$(envval DB_HOST)" -u"$(envval DB_USERNAME)" \
              -p"$(envval DB_PASSWORD)" "$(envval DB_DATABASE)" > "$DUMP"
    # A dump that silently wrote nothing is worse than no dump, because it is
    # trusted. mysqldump signs off with a completion line; check for it.
    if ! tail -5 "$DUMP" | grep -q 'Dump completed'; then
        echo "    Backup did not complete — stopping before the migration." >&2
        exit 1
    fi
    echo "    $DUMP ($(du -h "$DUMP" | cut -f1))"
fi

cp .env "$BACKUP_DIR/.env-$(date +%F-%H%M)"

# ---------------------------------------------------------------------------
echo "==> Fetching $BRANCH"
BEFORE="$(git -C "$SITE" rev-parse HEAD)"

git config --global --add safe.directory "$SITE" 2>/dev/null || true
git -C "$SITE" fetch origin --prune
# --ff-only rather than a merge: a deploy should replay what was reviewed, not
# invent a merge commit on the server that exists nowhere else.
git -C "$SITE" merge --ff-only "origin/$BRANCH"

AFTER="$(git -C "$SITE" rev-parse HEAD)"
echo "    $(git -C "$SITE" log --oneline -1)"

if [ "$BEFORE" = "$AFTER" ]; then
    echo "    Already up to date."
else
    echo "    $(git -C "$SITE" rev-list --count "$BEFORE..$AFTER") new commit(s)"
fi

# ---------------------------------------------------------------------------
echo "==> Dependencies"
# --no-dev keeps test and debug packages off the server; the optimised autoloader
# is a measurable difference on every request.
COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Migrating"
$PHP artisan migrate --force

echo "==> Rebuilding caches"
# Clear before caching: a stale config cache is the classic "I changed .env and
# nothing happened" deploy, and it hides changes to mail and FCM settings in
# particular.
$PHP artisan config:clear
$PHP artisan config:cache
$PHP artisan route:clear
$PHP artisan route:cache
$PHP artisan view:clear
$PHP artisan view:cache
$PHP artisan event:cache

echo "==> Storage link"
# Without this every employee photo 404s with nothing in the log.
$PHP artisan storage:link || true

echo "==> Restarting the queue worker"
# The running worker holds the code it booted with. Without this it keeps
# executing the previous revision until it retires on its own.
$PHP artisan queue:restart || true
if systemctl list-unit-files 2>/dev/null | grep -q '^hrms-worker'; then
    systemctl restart hrms-worker || echo "    (could not restart hrms-worker — do it by hand)"
fi

echo "==> Ownership"
chown -R "$OWNER:$OWNER" "$SITE"

# ---------------------------------------------------------------------------
echo "==> Preflight"
# Fails the deploy loudly rather than leaving a misconfigured site serving. On a
# staging box it is advisory: MAIL_MAILER=log and a demo panel are the point
# there, and failing on them would only teach people to skip the script.
if [ "${ALLOW_NON_PRODUCTION:-0}" = "1" ]; then
    sudo -u "$OWNER" $PHP artisan hrms:preflight || \
        echo "    (advisory only on a non-production install)"
else
    sudo -u "$OWNER" $PHP artisan hrms:preflight
fi

echo "==> Done"
