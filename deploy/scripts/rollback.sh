#!/usr/bin/env bash
#
# Point `current` back at an earlier release. Run on the server.
#
#   sudo -u deploy ./rollback.sh /var/www/cargo-rush/production
#   sudo -u deploy ./rollback.sh /var/www/cargo-rush/production 41-a1b2c3d
#
# With no release name it steps back one. With a name it goes to that one.
#
# What this does NOT do is reverse migrations. Schema changes are forward-only
# here: if the release you are backing out of dropped a column, the older code
# will not find it and rolling back will not bring it back. For that case,
# write and deploy a corrective migration instead.
#
set -euo pipefail

DEPLOY_PATH="${1:?deploy path required}"
TARGET="${2:-}"

RELEASES_DIR="$DEPLOY_PATH/releases"
PHP="${PHP_BIN:-php8.4}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m!!!\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$RELEASES_DIR" ] || fail "no releases directory at $RELEASES_DIR"

current_name="$(basename "$(readlink -f "$DEPLOY_PATH/current")")"

if [ -z "$TARGET" ]; then
  # Newest first, skip the one in use, take the next.
  TARGET="$(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null \
    | xargs -n1 basename \
    | grep -vx "$current_name" \
    | head -n1 || true)"
  [ -n "$TARGET" ] || fail "no earlier release to roll back to"
fi

RELEASE_DIR="$RELEASES_DIR/$TARGET"
[ -d "$RELEASE_DIR" ] || fail "release '$TARGET' does not exist in $RELEASES_DIR"
[ "$TARGET" = "$current_name" ] && fail "release '$TARGET' is already current"

log "Rolling back from $current_name to $TARGET"

# The release may have been pruned of its caches, or been built against a
# different .env. Rebuild them against the shared .env as it stands now.
cd "$RELEASE_DIR/CargoApi"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

ln -sfn "$RELEASE_DIR" "$DEPLOY_PATH/current.tmp"
mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

sudo -n /usr/bin/systemctl reload "${PHP}-fpm"
"$PHP" artisan queue:restart
sudo -n /usr/bin/systemctl reload nginx

log "Now serving $TARGET"
log "Migrations were not reversed — check the schema if that release changed it."
