#!/usr/bin/env bash
#
# Server-side half of a deploy. Runs on the VPS, inside the freshly uploaded
# release, and is shipped with the release itself so the script that activates
# a build is always the one that was committed alongside it.
#
#   usage: bin/release.sh <deploy_path> <release_name>
#
# Layout it assumes and maintains:
#
#   <deploy_path>/
#     shared/.env              hand-written once, never deployed
#     shared/storage/          uploads, logs, sessions — survives releases
#     releases/<name>/         one directory per deploy
#     current -> releases/...  what nginx serves
#
set -euo pipefail

DEPLOY_PATH="${1:?deploy path required}"
RELEASE="${2:?release name required}"

RELEASE_DIR="$DEPLOY_PATH/releases/$RELEASE"
SHARED_DIR="$DEPLOY_PATH/shared"
API_DIR="$RELEASE_DIR/CargoApi"
PHP="${PHP_BIN:-php8.5}"

# staging or production — the unit suffix for this environment's queue worker
# and scheduler, taken from the deploy path so one script serves both.
ENV_NAME="$(basename "$DEPLOY_PATH")"

# How many past releases to keep for rollback.
KEEP_RELEASES="${KEEP_RELEASES:-5}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m ! \033[0m%s\n' "$*"; }
fail() { printf '\033[1;31m!!!\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$RELEASE_DIR" ] || fail "release $RELEASE was not uploaded to $RELEASE_DIR"
[ -f "$SHARED_DIR/.env" ] || fail "no $SHARED_DIR/.env — run provision.sh and write it before the first deploy"

# ---------------------------------------------------------------------------
# Wire the release into the shared state
# ---------------------------------------------------------------------------
log "Linking shared .env and storage"

ln -sfn "$SHARED_DIR/.env" "$API_DIR/.env"

# The build ships an empty storage skeleton; shared/storage is the real one.
# Seed anything missing (a first deploy, or a directory Laravel added in a
# later version) without touching files already there.
if [ -d "$RELEASE_DIR/skel/storage" ]; then
  mkdir -p "$SHARED_DIR/storage"
  cp -rn "$RELEASE_DIR/skel/storage/." "$SHARED_DIR/storage/" 2>/dev/null || true
  rm -rf "$RELEASE_DIR/skel"
fi

rm -rf "$API_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$API_DIR/storage"

# public/storage -> ../storage/app/public, for anything served over HTTP
# (company logos, proof-of-delivery images).
rm -rf "$API_DIR/public/storage"
ln -sfn "$SHARED_DIR/storage/app/public" "$API_DIR/public/storage"

# No chmod over shared/storage here, deliberately.
#
# Permissions on that tree are a provisioning concern: provision.sh owns it to
# the deploy user, sets the setgid bit and a default ACL granting both this
# user and www-data rwX, so everything created later — by a deploy, by
# php-fpm, by the queue worker — already comes out right.
#
# A `chmod -R` on every release would add nothing and would eventually fail:
# php-fpm writes cached views and session files as www-data, and chmod needs
# ownership, which the ACL does not give. The first deploy after the app had
# served a single page would die here, pointing at storage rather than at the
# release that happened to follow.

# ---------------------------------------------------------------------------
# Migrate, then cache. In that order: a cached config that a migration has not
# caught up with is a boot that half works.
# ---------------------------------------------------------------------------
cd "$API_DIR"

log "Running migrations"
"$PHP" artisan migrate --force --no-interaction

log "Rebuilding caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

# ---------------------------------------------------------------------------
# Flip. `ln -sfn` into a temp name then `mv -T` is the atomic part: a rename
# over an existing symlink either happened or did not, so no request ever sees
# a missing `current`.
# ---------------------------------------------------------------------------
log "Activating release $RELEASE"
ln -sfn "$RELEASE_DIR" "$DEPLOY_PATH/current.tmp"
mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

# ---------------------------------------------------------------------------
# Tell the long-running processes about it
# ---------------------------------------------------------------------------
# PHP-FPM caches compiled bytecode against the resolved (realpath) file, so a
# reload is what makes the new release's code the code that runs.
log "Reloading PHP-FPM"
sudo -n /usr/bin/systemctl reload "${PHP}-fpm"

# Graceful: workers finish the job in hand, exit, and systemd starts them again
# on the new symlink.
# `queue:restart` alone is not enough. It sets a flag that running workers
# notice and exit on, so systemd's Restart=always brings them back on the new
# release — but it does nothing at all to a worker that is not running, and
# after provisioning it is not: the unit is enabled and left stopped, because
# at that point there is no release for it to run.
#
# So the first deploy would finish green with no worker ever started, and
# every queued job would sit in the table unprocessed, looking like features
# that quietly do nothing.
#
# `systemctl restart` covers both cases, and is still graceful — queue:work
# handles SIGTERM by finishing the job in hand, with TimeoutStopSec=90 to do
# it in. The sudoers rule permits exactly this unit and nothing else.
log "Restarting queue workers"
if sudo -n /usr/bin/systemctl restart "cargo-queue-$ENV_NAME" 2>/dev/null; then
  log "  cargo-queue-$ENV_NAME restarted"
else
  warn "could not restart cargo-queue-$ENV_NAME; signalling running workers instead"
  "$PHP" artisan queue:restart
fi

log "Reloading nginx"
sudo -n /usr/bin/systemctl reload nginx

# ---------------------------------------------------------------------------
# Prune
# ---------------------------------------------------------------------------
log "Pruning old releases (keeping $KEEP_RELEASES)"
cd "$DEPLOY_PATH/releases"
current_target="$(readlink -f "$DEPLOY_PATH/current")"
# shellcheck disable=SC2012
ls -1dt ./*/ 2>/dev/null | tail -n "+$((KEEP_RELEASES + 1))" | while read -r old; do
  old_abs="$(readlink -f "$old")"
  # An `if` rather than `[ … ] && continue`: under `set -e` a bare test that
  # comes out false is a non-zero status at the end of the loop body, and this
  # body runs in a subshell because of the pipe. Not worth the argument.
  if [ "$old_abs" != "$current_target" ]; then
    echo "    removing $(basename "$old_abs")"
    rm -rf "$old_abs"
  fi
done

log "Deployed $RELEASE to $ENV_NAME"
