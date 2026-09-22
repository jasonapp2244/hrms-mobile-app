#!/usr/bin/env bash
#
# Employment Management Portal — move a live install onto the split repository.
#
# Run this ONCE, on a server that was installed from the combined repository
# (the one that held this backend and the Flutter app in one tree). After it,
# deploy/deploy.sh works normally for every release.
#
#   git clone https://github.com/jasonapp2244/hrms-mobile-app.git ~/hrms-new
#   bash ~/hrms-new/deploy/migrate-to-split-repo.sh /path/to/the/site
#
# The site path is optional and defaults to the current directory. On the
# managed webspace that is:
#
#   /home/devonlinetestserver-hrams/htdocs/hrams.devonlinetestserver.com
#
# WHY THIS EXISTS INSTEAD OF A PULL
#
# deploy.sh fast-forwards, deliberately: a deploy should replay what was
# reviewed rather than invent a merge commit on the server. It cannot
# fast-forward onto this repository. The history was rewritten to purge a 59 MB
# archive that had a .env inside it, so every commit has a new hash and the two
# histories share no commit at all. Pointing the old checkout at the new remote
# and pulling will fail, and should.
#
# WHAT IT PROTECTS
#
# The application directory keeps its exact path. The document root, the cron
# entries and anything else that names a path all keep working, and there is no
# panel change to get wrong. Only the contents of that directory are replaced,
# and the directory itself is never removed — a panel host can be unhappy about
# its document root disappearing even for a second.
#
# Nothing is destroyed. The old install is moved intact to a sibling directory
# and the rollback is one command, printed at the end and on any failure.

set -euo pipefail

STAMP="$(date +%F-%H%M)"
NEW="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE_ARG="${1:-$PWD}"

# ---------------------------------------------------------------------------
# Which PHP
#
# On managed webspace the CLI default is routinely older than the PHP the web
# server runs, and a migration executed by the wrong binary is a bad way to find
# that out. Prefer an explicit one, then the version this application requires.
# ---------------------------------------------------------------------------
if [ -n "${PHP:-}" ]; then
    :
elif command -v php8.3 >/dev/null 2>&1; then
    PHP=php8.3
else
    PHP=php
fi

if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "No PHP binary found (tried \$PHP, php8.3, php)." >&2
    exit 1
fi

COMPOSER="${COMPOSER_BIN:-}"
if [ -z "$COMPOSER" ]; then
    if command -v composer >/dev/null 2>&1; then COMPOSER="composer"
    elif [ -f "$HOME/composer.phar" ];  then COMPOSER="$PHP $HOME/composer.phar"
    else
        echo "Composer not found. Install it, or set COMPOSER_BIN." >&2
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# Where things are
#
# Same detection as deploy.sh, and for the same reason: the subdirectory holding
# the application has been called both hrms/ and emp/, and on the documented VPS
# layout there is no subdirectory at all. Detected rather than configured.
# ---------------------------------------------------------------------------
SITE="$(cd "$SITE_ARG" 2>/dev/null && git rev-parse --show-toplevel 2>/dev/null || true)"
[ -z "$SITE" ] && SITE="$(cd "$SITE_ARG" && pwd)"

APP=""
for CANDIDATE in "$SITE" "$SITE/hrms" "$SITE/emp"; do
    if [ -f "$CANDIDATE/artisan" ]; then APP="$CANDIDATE"; break; fi
done
if [ -z "$APP" ]; then
    echo "Cannot find artisan under $SITE (tried ./, ./hrms and ./emp)." >&2
    echo "Pass the site directory explicitly: bash $0 /path/to/site" >&2
    exit 1
fi

if [ ! -f "$APP/.env" ]; then
    echo "No .env in $APP — this does not look like a working install." >&2
    exit 1
fi

if [ "$NEW" = "$APP" ] || [ "$NEW" = "$SITE" ]; then
    echo "The new checkout must be somewhere else, not inside the install." >&2
    echo "Clone it to ~/hrms-new and run this from there." >&2
    exit 1
fi

if [ ! -f "$NEW/artisan" ] || [ ! -d "$NEW/.git" ]; then
    echo "$NEW is not a checkout of the backend repository." >&2
    exit 1
fi

OLD="$(dirname "$APP")/pre-split-$STAMP"
APP_ENV_VALUE="$(grep -E '^APP_ENV=' "$APP/.env" | head -1 | cut -d= -f2- | tr -d '"'\''' || true)"

echo "==> Migrating to the split repository"
echo "    site:      $SITE"
echo "    app:       $APP          (this path does not change)"
echo "    new code:  $NEW"
echo "    old code:  $OLD          (kept)"
echo "    env:       ${APP_ENV_VALUE:-unset}"
echo "    php:       $($PHP -r 'echo PHP_VERSION;')"
echo

if [ "$APP_ENV_VALUE" != "production" ] && [ "${ALLOW_NON_PRODUCTION:-0}" != "1" ]; then
    echo "Refusing: APP_ENV is '${APP_ENV_VALUE:-unset}', not 'production'." >&2
    echo "If this really is staging, re-run with ALLOW_NON_PRODUCTION=1." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
echo "==> Preparing the new checkout"
# Everything expensive happens while the site is still up and serving. The
# window where it is down is the two moves below and the migration, not this.

cp "$APP/.env" "$NEW/.env"

# storage/ is the install, not the code: employee photos, documents, exports and
# any backups taken on this box. A clone has the empty skeleton and nothing else.
for DIR in app backups; do
    if [ -d "$APP/storage/$DIR" ]; then
        mkdir -p "$NEW/storage/$DIR"
        cp -a "$APP/storage/$DIR/." "$NEW/storage/$DIR/"
        echo "    carried storage/$DIR"
    fi
done

( cd "$NEW" && $COMPOSER install --no-dev --optimize-autoloader --no-interaction --prefer-dist )

chmod -R 775 "$NEW/storage" "$NEW/bootstrap/cache"

# ---------------------------------------------------------------------------
echo "==> Backup"
# Taken from the OLD install, with the old code, before anything moves. A
# migration that goes wrong then costs minutes rather than a day of punches.
cd "$APP"
BACKUP_DIR="$APP/storage/backups"
mkdir -p "$BACKUP_DIR"

if $PHP artisan list --raw 2>/dev/null | grep -q '^db:backup'; then
    $PHP artisan db:backup --verify
else
    echo "    db:backup not in this revision — using mysqldump"
    envval() { grep -E "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | tr -d '"'\'''; }
    DUMP="$BACKUP_DIR/pre-split-$STAMP.sql"
    mysqldump -h"$(envval DB_HOST)" -u"$(envval DB_USERNAME)" \
              -p"$(envval DB_PASSWORD)" "$(envval DB_DATABASE)" > "$DUMP"
    # A dump that silently wrote nothing is worse than no dump, because it is
    # trusted. mysqldump signs off with a completion line; check for it.
    if ! tail -5 "$DUMP" | grep -q 'Dump completed'; then
        echo "    Backup did not complete — stopping before anything moves." >&2
        exit 1
    fi
    echo "    $DUMP ($(du -h "$DUMP" | cut -f1))"
    cp -a "$DUMP" "$NEW/storage/backups/" 2>/dev/null || true
fi

cp "$APP/.env" "$BACKUP_DIR/.env-pre-split-$STAMP"

# ---------------------------------------------------------------------------
echo "==> Maintenance mode"
$PHP artisan down --secret="deploying-now" --render="errors::503" || true

rollback() {
    echo >&2
    echo "FAILED — rolling back is one command:" >&2
    echo >&2
    echo "  rm -rf '$APP' && mv '$OLD' '$APP' && cd '$APP' && $PHP artisan up" >&2
    echo >&2
    echo "The database backup is in $BACKUP_DIR." >&2
}
trap rollback ERR

# ---------------------------------------------------------------------------
echo "==> Swapping the code"
# The directory itself is kept and only its contents move, because a document
# root that vanishes even briefly upsets some panel hosts. mindepth 1 so the
# directory survives; maxdepth 1 so this is a move of entries, not a walk of the
# whole tree; -exec ... + so dotfiles are included, which a glob would miss.
mkdir -p "$OLD"
find "$APP" -mindepth 1 -maxdepth 1 -exec mv -t "$OLD" {} +
cp -a "$NEW/." "$APP/"

# The old repository's .git may sit a level above the application, when the app
# was the hrms/ subdirectory of the combined checkout. Left there it makes the
# new checkout look like a nested repository and confuses every later `git
# status`. Renamed rather than deleted — it is the only local copy of whatever
# was never pushed.
if [ "$SITE" != "$APP" ] && [ -d "$SITE/.git" ]; then
    mv "$SITE/.git" "$SITE/.git.pre-split-$STAMP"
    echo "    retired $SITE/.git (renamed, not deleted)"
fi

cd "$APP"

# ---------------------------------------------------------------------------
echo "==> Migrating"
$PHP artisan migrate --force

echo "==> Rebuilding caches"
# Clear before caching: a stale config cache is the classic "I changed .env and
# nothing happened", and it hides mail and FCM settings in particular.
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
$PHP artisan queue:restart || true
if systemctl list-unit-files 2>/dev/null | grep -q '^emp-worker'; then
    systemctl restart emp-worker || echo "    (could not restart emp-worker — do it by hand)"
fi

# ---------------------------------------------------------------------------
echo "==> Preflight"
if [ "${ALLOW_NON_PRODUCTION:-0}" = "1" ]; then
    $PHP artisan emp:preflight --non-production
else
    $PHP artisan emp:preflight
fi

trap - ERR
$PHP artisan up

# ---------------------------------------------------------------------------
cat <<DONE

==> Done

The application is at $APP, which is where it was before, so the document root
and the cron entries need no change. Confirm that nothing had to:

    cd "$APP" && git remote -v && git log --oneline -1

Check these two before you walk away:

  1. The site loads over https and you can sign in.
  2. The document root is the application's public/ and not a level above it:

         curl -sI https://hrams.devonlinetestserver.com/.env | head -1

     Anything other than 403 or 404 means .env is being served to the internet.
     That is how a .env came to be in this repository's history in the first
     place, and it is worth thirty seconds to be sure.

The old install is at:

    $OLD

Keep it until the site has been through a working day. Then it is safe to
remove, along with any $SITE/.git.pre-split-* left behind.

Rolling back, if something surfaces later:

    rm -rf "$APP" && mv "$OLD" "$APP" && cd "$APP" && $PHP artisan up

Every release after this one is the normal deploy:

    cd "$APP" && bash deploy/deploy.sh
DONE
