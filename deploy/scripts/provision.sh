#!/usr/bin/env bash
#
# One-time server setup for one environment. Run it once as root per
# environment; running it again is safe and only fills in what is missing.
#
#   sudo ./provision.sh staging    staging.aya-it.online  staging-app.aya-it.online
#   sudo ./provision.sh production api.aya-it.online      app.aya-it.online
#
# Two hostnames per environment: the API and the SPA get a vhost each. They
# may be the same name, in which case one vhost serves both and the session
# cookie is scoped to that host alone.
#
# Targets Ubuntu. It installs nginx, PHP, MySQL and the two systemd units,
# lays out the release directories, and leaves you with a server that a
# `git push` can deploy to.
#
# PHP comes from the distro where the distro has it (26.04 ships 8.5) and from
# ondrej/php only where it does not — and only where that PPA actually
# publishes for the release, which for 26.04 "resolute" it does not.
# PHP_VERSION overrides the default.
#
set -euo pipefail

ENV_NAME="${1:-}"
API_HOST="${2:-}"
SPA_HOST="${3:-}"

if [ -z "$ENV_NAME" ] || [ -z "$API_HOST" ] || [ -z "$SPA_HOST" ]; then
  echo "usage: sudo $0 <staging|production> <api-host> <spa-host>" >&2
  exit 64
fi

case "$ENV_NAME" in
  staging|production) ;;
  *) echo "environment must be staging or production, got '$ENV_NAME'" >&2; exit 64 ;;
esac

[ "$(id -u)" -eq 0 ] || { echo "run this with sudo" >&2; exit 1; }

PHP_VERSION="${PHP_VERSION:-8.5}"
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

# The cookie domain has to be readable from both hosts, so it is the deepest
# domain they share — the common suffix of their labels, not a guess at the
# registrable domain. For api.aya-it.online and app.aya-it.online that is
# aya-it.online; for api.x.co.uk and app.x.co.uk it is x.co.uk, which the
# usual "last two labels" shortcut gets wrong.
#
# Same host for both means no sharing is needed, and the cookie stays scoped
# to that one host, which is strictly better.
common_domain_suffix() {
  local -a a b
  local out="" i j
  IFS='.' read -ra a <<< "$1"
  IFS='.' read -ra b <<< "$2"
  i=$(( ${#a[@]} - 1 )); j=$(( ${#b[@]} - 1 ))
  while [ "$i" -ge 0 ] && [ "$j" -ge 0 ] && [ "${a[$i]}" = "${b[$j]}" ]; do
    out="${a[$i]}${out:+.}$out"
    i=$((i - 1)); j=$((j - 1))
  done
  printf '%s' "$out"
}

if [ "$API_HOST" = "$SPA_HOST" ]; then
  SESSION_DOMAIN="$API_HOST"
else
  shared="$(common_domain_suffix "$API_HOST" "$SPA_HOST")"
  # Fewer than two labels means they share only a TLD — ".com" — which no
  # browser will accept and which would be a grave thing to set if one did.
  case "$shared" in
    *.*) SESSION_DOMAIN=".$shared" ;;
    *)
      echo "$API_HOST and $SPA_HOST share no usable parent domain ('$shared')." >&2
      echo "Cross-origin Sanctum needs them under one registrable domain." >&2
      exit 78
      ;;
  esac
fi
SESSION_DOMAIN="${SESSION_DOMAIN_OVERRIDE:-$SESSION_DOMAIN}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m ! \033[0m%s\n' "$*"; }

# ---------------------------------------------------------------------------
log "Installing packages"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# The distro first, the PPA only if it cannot help.
#
# ondrej/php is the reflex on Ubuntu, but it does not publish for every
# release — 26.04 "resolute" has no suite there at all, and adding a source
# that 404s breaks every subsequent `apt-get update` on the box, which is a
# much worse problem than the one it was added to solve. Recent Ubuntu also
# ships a PHP new enough on its own: 26.04 has 8.5.
# Deliberately not `apt-cache policy … | grep -q`. `grep -q` exits the moment
# it matches, apt-cache then dies of SIGPIPE, and `set -o pipefail` reports the
# pipeline as failed — so a successful match reads as "package not found".
# Capture first, test after: no pipe, nothing to race.
have_php_packages() {
  local policy
  policy=$(apt-cache policy "php${PHP_VERSION}-fpm" 2>/dev/null) || return 1
  case "$policy" in
    *"Candidate: "[0-9]*) return 0 ;;
    *)                    return 1 ;;
  esac
}

if ! have_php_packages; then
  . /etc/os-release
  ppa_url="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/$VERSION_CODENAME/Release"

  if [ "$(curl -fsS -o /dev/null -w '%{http_code}' "$ppa_url" || echo 000)" = "200" ]; then
    log "php${PHP_VERSION} is not in the distro repos — adding ondrej/php"
    apt-get install -y -qq software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
  else
    warn "ondrej/php has no packages for $VERSION_CODENAME; using the distro only"
  fi
fi

if ! have_php_packages; then
  available=$(apt-cache search --names-only '^php8\.[0-9]-fpm$' 2>/dev/null \
              | awk '{print $1}' | sed 's/-fpm//' | tr '\n' ' ')
  echo "php${PHP_VERSION}-fpm is not installable on this box." >&2
  echo "Available here: ${available:-none}" >&2
  echo "Re-run with PHP_VERSION set to one of those." >&2
  exit 78
fi
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
#
# The file is named after the user, not the project: running this twice on one
# box with DEPLOY_USER set differently each time would otherwise have the
# second run overwrite the first user's rule and silently break its deploys.
{
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart cargo-queue-staging"
  echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart cargo-queue-production"
} > "/etc/sudoers.d/cargo-rush-$DEPLOY_USER"
chmod 440 "/etc/sudoers.d/cargo-rush-$DEPLOY_USER"
visudo -cf "/etc/sudoers.d/cargo-rush-$DEPLOY_USER" >/dev/null

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
# Captured rather than piped into `grep -q`, for the same reason as the PHP
# check above. Getting this backwards would be worse here: the script would
# decide an existing database does not exist and run CREATE DATABASE on it,
# which fails, which under `set -e` ends the run half-provisioned.
existing_db=$(mysql -N -B -e "SHOW DATABASES LIKE '$DB_DATABASE'" 2>/dev/null || true)
if [ -n "$existing_db" ]; then
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
      -e "s|__API_HOST__|$API_HOST|g" \
      -e "s|__SPA_HOST__|$SPA_HOST|g" \
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
log "Installing the nginx vhosts"
# ---------------------------------------------------------------------------
# Each is left alone once it exists, for the same reason as shared/.env: this
# is not the only thing that writes them. `certbot --nginx` rewrites the file
# to add the 443 server block and the redirect, so regenerating from the
# template would throw the TLS config away and drop the site back to plain
# HTTP — and with SESSION_SECURE_COOKIE=true that reads as "nobody can log in
# any more", a long way from the command that caused it.
#
# FORCE_NGINX=1 regenerates anyway, keeping a timestamped backup.
install_vhost() {
  local role="$1" host="$2" template="$3"
  local vhost="/etc/nginx/sites-available/cargo-$ENV_NAME-$role"

  if [ -f "$vhost" ] && [ "${FORCE_NGINX:-0}" != "1" ]; then
    warn "$vhost exists — not regenerating it (FORCE_NGINX=1 to override)"
  else
    if [ -f "$vhost" ]; then
      local backup="$vhost.bak.$(date +%Y%m%d%H%M%S)"
      cp -a "$vhost" "$backup"
      warn "regenerating $vhost — previous version kept at $backup"
      warn "re-run: certbot --nginx -d $host --redirect"
    fi
    sed -e "s|__API_HOST__|$API_HOST|g" \
        -e "s|__SPA_HOST__|$SPA_HOST|g" \
        -e "s|__DEPLOY_PATH__|$DEPLOY_PATH|g" \
        -e "s|__ENV_NAME__|$ENV_NAME|g" \
        -e "s|__PHP_VERSION__|$PHP_VERSION|g" \
        "$HERE/nginx/$template" > "$vhost"
  fi
  ln -sfn "$vhost" "/etc/nginx/sites-enabled/cargo-$ENV_NAME-$role"
}

install_vhost api "$API_HOST" api.conf.template

# One host serving both would mean two server blocks claiming the same name,
# which nginx warns about and resolves by ignoring one of them. The API vhost
# already answers on that name; the SPA would simply be unreachable, so say so
# rather than installing something that cannot work.
if [ "$SPA_HOST" = "$API_HOST" ]; then
  warn "SPA and API share the hostname $API_HOST — installing the API vhost only."
  warn "Serve them apart, or use the same-origin layout where one vhost does both."
else
  install_vhost spa "$SPA_HOST" spa.conf.template
fi

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
echo "  2. Point both names at this box in DNS, then get certificates:"
echo "       certbot --nginx -d $API_HOST --redirect"
if [ "$SPA_HOST" != "$API_HOST" ]; then
echo "       certbot --nginx -d $SPA_HOST --redirect"
fi
echo ""
echo "  3. Check $ENV_FILE. DB_PASSWORD is set; MAIL_MAILER is still 'log',"
echo "     so password resets and invoices are written to the log, not sent."
echo ""
echo "  4. Push to the '$DEPLOY_BRANCH' branch. The GitHub environment needs:"
echo "       API_URL = https://$API_HOST"
echo "       APP_URL = https://$SPA_HOST"
echo ""
echo "  Session cookie domain for this environment: $SESSION_DOMAIN"
if [ "$SPA_HOST" != "$API_HOST" ]; then
echo "  (shared parent of $API_HOST and $SPA_HOST, so the SPA can read the"
echo "   XSRF token the API issues — a host-only cookie would 419 every write)"
fi
echo ""
echo "  Host key for the SSH_KNOWN_HOSTS secret:"
echo "       ssh-keyscan -t rsa,ecdsa,ed25519 <this box's address>"
echo ""
