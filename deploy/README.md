# Deployment

Two environments, one VPS each (or one box with both — the layout keeps them
apart either way), driven entirely by which branch you push to.

| Branch    | GitHub Environment | Deploy path                     | What it is |
|-----------|--------------------|---------------------------------|------------|
| `staging` | `staging`          | `/var/www/cargo-rush/staging`    | Where work lands first |
| `main`    | `production`       | `/var/www/cargo-rush/production` | Live |

`cargoApp` (Expo) is not part of this. It ships through EAS to the Play Store
and has its own `eas.json`.

## How a deploy works

A push runs `.github/workflows/deploy.yml`:

1. **test** — the full Pest suite and an Angular production build, the same job
   pull requests run. A failure here stops everything.
2. **build** — installs Composer dependencies with `--no-dev`, builds CargoUI
   with `--configuration production`, and tars the result. The VPS never needs
   Composer, Node, or a build toolchain.
3. **deploy** — uploads the tarball into `releases/<run>-<sha>/`, then runs
   `bin/release.sh` on the server, which migrates, caches config, and flips the
   `current` symlink. A smoke test hits `/up` and `/`.

Releases are kept for rollback; the five most recent survive pruning.

### On-disk layout

```
/var/www/cargo-rush/staging/
├── shared/
│   ├── .env                    written once by provision.sh, never deployed
│   └── storage/                uploads, logs, sessions — survives every release
├── releases/
│   ├── 41-a1b2c3d/
│   │   ├── CargoApi/           .env and storage are symlinks into shared/
│   │   └── CargoUI/browser/
│   └── 42-e4f5g6h/
└── current -> releases/42-e4f5g6h
```

### One origin, two apps

nginx serves `CargoUI/browser` as the document root and carves out
`/api`, `/sanctum`, `/login`, `/logout`, `/up`, `/build` and `/storage` for
Laravel. That is deliberate: `CargoUI/src/environments/environment.prod.ts`
sets `apiUrl: ''`, so the SPA reaches the API by path. Same origin means no
CORS preflight and a first-party Sanctum session cookie.

Anything else — `/employees`, `/payroll/…` — falls through to `index.html` for
the Angular router to handle.

## First-time setup

### 1. Provision each server

Copy this directory to the box and run it as root, once per environment:

```bash
scp -r deploy/ root@<host>:/tmp/
ssh root@<host>
bash /tmp/deploy/scripts/provision.sh staging staging.cargorush.example
```

It installs nginx, PHP 8.3 + FPM, MySQL and certbot; creates the `deploy` user
and a `cargo_staging` database; writes `shared/.env` with a generated `APP_KEY`
and database password; installs the vhost, the queue worker and the scheduler
timer; and prints what is left to do.

Override the defaults with environment variables if you need to:
`PHP_VERSION`, `DEPLOY_USER`, `DEPLOY_ROOT`.

### 2. Give GitHub a key

On your laptop, one key pair per environment:

```bash
ssh-keygen -t ed25519 -C cargo-rush-ci-staging -f ~/.ssh/cargo_ci_staging -N ''
```

Append the **public** half to the server:

```bash
ssh root@<host> "cat >> /home/deploy/.ssh/authorized_keys" < ~/.ssh/cargo_ci_staging.pub
```

### 3. DNS and TLS

Point the hostname at the box, then:

```bash
ssh root@<host> "certbot --nginx -d staging.cargorush.example --redirect"
```

certbot rewrites the vhost to add the 443 block. Do this **before** the first
deploy — the smoke test requests `APP_URL`, and `SESSION_SECURE_COOKIE=true` in
the env template means sessions will not work over plain HTTP anyway.

### 4. Create the GitHub Environments

`Settings → Environments`, one named `staging` and one named `production`.

Secrets, per environment:

| Secret            | Value |
|-------------------|-------|
| `SSH_HOST`        | The server's hostname or IP |
| `SSH_USER`        | `deploy` |
| `SSH_PRIVATE_KEY` | Contents of `~/.ssh/cargo_ci_staging` — the private half, whole file including the BEGIN/END lines |
| `SSH_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 staging.cargorush.example` |

Variables, per environment:

| Variable      | Value |
|---------------|-------|
| `DEPLOY_PATH` | `/var/www/cargo-rush/staging` |
| `APP_URL`     | `https://staging.cargorush.example` |
| `SSH_PORT`    | Only if not 22 |

`SSH_KNOWN_HOSTS` is a secret rather than an `ssh-keyscan` at deploy time on
purpose: keyscan trusts whatever answers on the night, which is not
verification.

On the `production` environment, also turn on **Required reviewers**. It is the
one thing that stops an accidental push to `main` going straight to live.

### 5. Push

```bash
git push origin staging
```

Watch it under Actions. The run ends with a `GET /up -> 200`.

## Day-to-day

**Ship to staging** — merge into `staging`, or push to it.

**Promote to production** — open a PR from `staging` to `main` and merge it.
CI runs on the PR, then again on the push to `main`.

**Redeploy without a code change** — Actions → Deploy → Run workflow, pick the
branch.

**Roll back**:

Every release carries the script, so it is already on the box:

```bash
ssh deploy@<host>
P=/var/www/cargo-rush/production
$P/current/bin/rollback.sh $P              # step back one release
$P/current/bin/rollback.sh $P 41-a1b2c3d   # or to a named one
```

`ls -t $P/releases` shows what you can go back to.

Rollback does **not** reverse migrations. Schema is forward-only: if the
release you are backing out of dropped a column, the older code will not find
it. Fix that with a corrective migration, not a rollback.

**Logs**:

```bash
tail -f /var/www/cargo-rush/staging/shared/storage/logs/laravel-*.log
tail -f /var/log/cargo-rush/staging-queue.log
tail -f /var/log/nginx/cargo-staging-error.log
journalctl -u cargo-queue-staging -f
systemctl list-timers cargo-scheduler-staging
```

## Things that will bite you

**The queue worker runs old code until it is restarted.** `release.sh` calls
`queue:restart`, which asks workers to finish the job in hand and exit; systemd
starts them again against the new symlink. If jobs behave like the previous
release, check `systemctl status cargo-queue-<env>`.

**`opcache.validate_timestamps = 0`.** Deploys swap a symlink so the resolved
path changes and opcache invalidates on its own — but this means editing a file
in place on the server does nothing until FPM is reloaded. Which is the point:
don't edit files on the server.

**`SANCTUM_STATEFUL_DOMAINS` must contain the hostname.** Same-origin does not
exempt you from Sanctum's check. Leave it out and every authenticated request
from the browser comes back 401 while the login itself appears to succeed.

**Mail is `log` by default.** Password resets and invoice delivery are written
to `storage/logs` rather than sent until you put real SMTP credentials in
`shared/.env` and change `MAIL_MAILER`. Run `php artisan config:cache` after
editing that file, or the change will not take effect.

**Seeders never run automatically.** `release.sh` runs `migrate --force` and
nothing else. A fresh staging database is empty; seed it by hand once:

```bash
cd /var/www/cargo-rush/staging/current/CargoApi && php8.3 artisan db:seed --force
```
