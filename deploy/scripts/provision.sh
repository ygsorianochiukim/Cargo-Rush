#!/usr/bin/env bash
#
# One-time server setup for one environment. Run it once as root per
# environment; running it again is safe and only fills in what is missing.
#
#   sudo ./provision.sh staging    staging.cargorush.example
#   sudo ./provision.sh production cargorush.example
#
# Targets Ubuntu 22.04/24.04 with the ondrej/php PPA. It installs nginx, PHP,
# MySQL and the two systemd units, lays out the release directories, and
# leaves you with a server that a `git push` can deploy to.
#
set -euo pipefail

ENV_NAME="${1:-}"
SERVER_NAME="${2:-}"

if [ -z "$ENV_NAME" ] || [ -z "$SERVER_NAME" ]; then
  echo "usage: sudo $0 <staging|production> <server-name>" >&2
  exit 64
fi

case "$ENV_NAME" in
  staging|production) ;;
  *) echo "environment must be staging or production, got '$ENV_NAME'" >&2; exit 64 ;;
esac

[ "$(id -u)" -eq 0 ] || { echo "run this with sudo" >&2; exit 1; }

PHP_VERSION="${PHP_VERSION:-8.3}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/cargo-rush}"
DEPLOY_PATH="$DEPLOY_ROOT/$ENV_NAME"
DB_DATABASE="cargo_${ENV_NAME}"
DB_USERNAME="cargo_${ENV_NAME}"

# Staging keeps debug off too. A stack trace on a public URL is a stack trace
# on a public URL, whatever the box is called.
APP_ENV="$ENV_NAME"
APP_DEBUG="false"
LOG_LEVEL="$([ "$ENV_NAME" = production ] && echo warning || echo debug)"

# The cookie domain: apex plus subdomains for production, the exact host for
# staging, so a staging session cannot be presented to production.
if [ "$ENV_NAME" = production ]; then
  SESSION_DOMAIN=".${SERVER_NAME#www.}"
else
  SESSION_DOMAIN="$SERVER_NAME"
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m ! \033[0m%s\n' "$*"; }

# ---------------------------------------------------------------------------
log "Installing packages"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive

if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
  apt-get update -qq
  apt-get install -y -qq software-properties-common
  add-apt-repository -y ppa:ondrej/php
fi

apt-get update -qq
apt-get install -y -qq \
  nginx mysql-server rsync curl git unzip acl certbot python3-certbot-nginx \
  "php${PHP_VERSION}-fpm" \
  "php${PHP_VERSION}-cli" \
  "php${PHP_VERSION}-mysql" \
  "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-bcmath" \
  "php${PHP_VERSION}-intl" \
  "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-opcache"

# ---------------------------------------------------------------------------
log "Creating the deploy user"
# ---------------------------------------------------------------------------
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" --shell /bin/bash "$DEPLOY_USER"
fi
usermod -aG www-data "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"

# The release script reloads FPM and nginx. Exactly those two commands, with
# no password — a broader rule would make the CI key a root key.
{
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart cargo-queue-staging"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart cargo-queue-production"
} > /etc/sudoers.d/cargo-rush
chmod 440 /etc/sudoers.d/cargo-rush
visudo -cf /etc/sudoers.d/cargo-rush >/dev/null

# ---------------------------------------------------------------------------
log "Laying out $DEPLOY_PATH"
# ---------------------------------------------------------------------------
install -d -o "$DEPLOY_USER" -g www-data -m 2775 \
  "$DEPLOY_PATH" "$DEPLOY_PATH/releases" "$DEPLOY_PATH/shared"
install -d -o "$DEPLOY_USER" -g www-data -m 2775 \
  "$DEPLOY_PATH/shared/storage/app/public" \
  "$DEPLOY_PATH/shared/storage/framework/cache/data" \
  "$DEPLOY_PATH/shared/storage/framework/sessions" \
  "$DEPLOY_PATH/shared/storage/framework/views" \
  "$DEPLOY_PATH/shared/storage/logs"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 /var/log/cargo-rush

# setgid plus a default ACL: files the deploy user writes and files php-fpm
# writes both stay group-writable, which is the usual cause of a deploy that
# works and a runtime that cannot write its own log.
setfacl -R  -m u:www-data:rwX -m "u:$DEPLOY_USER:rwX" "$DEPLOY_PATH/shared/storage"
setfacl -dR -m u:www-data:rwX -m "u:$DEPLOY_USER:rwX" "$DEPLOY_PATH/shared/storage"

# ---------------------------------------------------------------------------
log "Creating the database"
# ---------------------------------------------------------------------------
DB_PASSWORD="$(openssl rand -base64 30 | tr -d '/+=' | head -c 32)"
DB_EXISTED=no
if mysql -N -B -e "SHOW DATABASES LIKE '$DB_DATABASE'" | grep -q "$DB_DATABASE"; then
  DB_EXISTED=yes
  warn "database $DB_DATABASE already exists — leaving it and its password alone"
else
  mysql -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';"
  mysql -e "GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'127.0.0.1';"
  mysql -e "FLUSH PRIVILEGES;"
fi

# ---------------------------------------------------------------------------
log "Writing shared/.env"
# ---------------------------------------------------------------------------
ENV_FILE="$DEPLOY_PATH/shared/.env"
if [ -f "$ENV_FILE" ]; then
  warn "$ENV_FILE exists — not overwriting it"
else
  sed -e "s|__APP_ENV__|$APP_ENV|g" \
      -e "s|__APP_DEBUG__|$APP_DEBUG|g" \
      -e "s|__LOG_LEVEL__|$LOG_LEVEL|g" \
      -e "s|__SERVER_NAME__|$SERVER_NAME|g" \
      -e "s|__SESSION_DOMAIN__|$SESSION_DOMAIN|g" \
      -e "s|__DB_DATABASE__|$DB_DATABASE|g" \
      -e "s|__DB_USERNAME__|$DB_USERNAME|g" \
      "$HERE/env/cargoapi.env.template" > "$ENV_FILE"

  if [ "$DB_EXISTED" = no ]; then
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" "$ENV_FILE"
  fi

  # APP_KEY has to exist before the first deploy — config:cache runs during the
  # release and fails without it. Generated here rather than by artisan,
  # because there is no application on the box yet.
  sed -i "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32)|" "$ENV_FILE"

  chown "$DEPLOY_USER:www-data" "$ENV_FILE"
  chmod 640 "$ENV_FILE"
fi

# ---------------------------------------------------------------------------
log "Installing the nginx vhost"
# ---------------------------------------------------------------------------
sed -e "s|__SERVER_NAME__|$SERVER_NAME|g" \
    -e "s|__DEPLOY_PATH__|$DEPLOY_PATH|g" \
    -e "s|__ENV_NAME__|$ENV_NAME|g" \
    -e "s|__PHP_VERSION__|$PHP_VERSION|g" \
    "$HERE/nginx/cargo-rush.conf.template" > "/etc/nginx/sites-available/cargo-$ENV_NAME"
ln -sfn "/etc/nginx/sites-available/cargo-$ENV_NAME" "/etc/nginx/sites-enabled/cargo-$ENV_NAME"
rm -f /etc/nginx/sites-enabled/default

# nginx will not start until `current` resolves, and `current` does not exist
# until the first deploy. A placeholder keeps the box serving something.
if [ ! -e "$DEPLOY_PATH/current" ]; then
  install -d -o "$DEPLOY_USER" -g www-data \
    "$DEPLOY_PATH/releases/0-placeholder/CargoUI/browser" \
    "$DEPLOY_PATH/releases/0-placeholder/CargoApi/public"
  echo '<!doctype html><title>Cargo Rush</title><p>Awaiting first deploy.' \
    > "$DEPLOY_PATH/releases/0-placeholder/CargoUI/browser/index.html"
  ln -sfn "$DEPLOY_PATH/releases/0-placeholder" "$DEPLOY_PATH/current"
  chown -h "$DEPLOY_USER:www-data" "$DEPLOY_PATH/current"
fi

nginx -t
systemctl reload nginx

# ---------------------------------------------------------------------------
log "Installing systemd units"
# ---------------------------------------------------------------------------
render_unit() {
  sed -e "s|__ENV_NAME__|$ENV_NAME|g" \
      -e "s|__DEPLOY_PATH__|$DEPLOY_PATH|g" \
      -e "s|__PHP_VERSION__|$PHP_VERSION|g" \
      -e "s|__DEPLOY_USER__|$DEPLOY_USER|g" \
      "$HERE/systemd/$1" > "/etc/systemd/system/$2"
}

render_unit cargo-queue.service.template     "cargo-queue-$ENV_NAME.service"
render_unit cargo-scheduler.service.template "cargo-scheduler-$ENV_NAME.service"
render_unit cargo-scheduler.timer.template   "cargo-scheduler-$ENV_NAME.timer"

systemctl daemon-reload
systemctl enable --now "cargo-scheduler-$ENV_NAME.timer"
# The queue worker is enabled but not started: there is no application to run
# until the first deploy lands. The deploy's `queue:restart` picks it up.
systemctl enable "cargo-queue-$ENV_NAME.service"

# ---------------------------------------------------------------------------
log "Hardening PHP"
# ---------------------------------------------------------------------------
PHP_INI="/etc/php/$PHP_VERSION/fpm/conf.d/99-cargo-rush.ini"
{
  echo "expose_php = Off"
  echo "memory_limit = 512M"
  echo "upload_max_filesize = 20M"
  echo "post_max_size = 24M"
  echo "max_execution_time = 60"
  echo ""
  echo "; Deploys swap a symlink, so the resolved path of every file changes"
  echo "; and the cache invalidates on its own. Checking timestamps on top of"
  echo "; that is work done on every request for nothing."
  echo "opcache.enable = 1"
  echo "opcache.validate_timestamps = 0"
  echo "opcache.memory_consumption = 192"
  echo "opcache.max_accelerated_files = 20000"
  echo "opcache.interned_strings_buffer = 16"
} > "$PHP_INI"

systemctl restart "php$PHP_VERSION-fpm"

# ---------------------------------------------------------------------------
DEPLOY_BRANCH="$([ "$ENV_NAME" = production ] && echo main || echo staging)"

log "Provisioned $ENV_NAME at $DEPLOY_PATH"
echo ""
echo "Still to do, in this order:"
echo ""
echo "  1. Add the CI public key so GitHub can log in:"
echo "       echo 'ssh-ed25519 AAAA... cargo-rush-ci' \\"
echo "         >> /home/$DEPLOY_USER/.ssh/authorized_keys"
echo ""
echo "  2. Point $SERVER_NAME at this box in DNS, then get a certificate:"
echo "       certbot --nginx -d $SERVER_NAME --redirect"
echo ""
echo "  3. Check $ENV_FILE. DB_PASSWORD is set; MAIL_MAILER is still 'log',"
echo "     so password resets and invoices are written to the log, not sent."
echo ""
echo "  4. Push to the '$DEPLOY_BRANCH' branch."
echo ""
echo "  Host key for the SSH_KNOWN_HOSTS secret (run from your laptop once DNS"
echo "  resolves, so you are trusting the name you will actually connect to):"
echo "       ssh-keyscan -t ed25519 $SERVER_NAME"
echo ""
