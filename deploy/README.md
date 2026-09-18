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

### Two hosts per environment

The API and the SPA are separate origins:

| | API | SPA |
|---|---|---|
| production | `api.aya-it.online` | `app.aya-it.online` |
| staging | `staging.aya-it.online` | `staging-app.aya-it.online` |

So there are two nginx vhosts per environment, from two templates:
`api.conf.template` serves `CargoApi/public` through php-fpm,
`spa.conf.template` serves `CargoUI/browser` as static files with an
`index.html` fallback for the Angular router. Both point into the same
`current` symlink.

Three things make the cross-origin part work, and all three live in
`shared/.env` rather than in nginx:

- **`FRONTEND_URL`** names the SPA host. `config/cors.php` reads it, and a
  credentialed request cannot use a wildcard, so it has to be exact.
- **`SANCTUM_STATEFUL_DOMAINS`** names the SPA host too. Without it Sanctum
  treats the call as a token request rather than a first-party session one,
  and every authenticated route answers 401 while login itself looks fine.
- **`SESSION_DOMAIN`** is the parent both hosts share — `.aya-it.online`.
  This is the subtle one. CargoUI's `csrfInterceptor` reads `XSRF-TOKEN` out
  of `document.cookie` to echo back as `X-XSRF-TOKEN`; a host-only cookie set
  by the API host is not readable by JavaScript on the SPA host, so every
  write comes back 419. `provision.sh` derives it as the common suffix of the
  two hostnames.

`SESSION_SAME_SITE=lax` is still correct despite the different host: "site"
means the registrable domain, and both sit under the same one.

Because `apiUrl` is compiled into the bundle, the SPA is built with a
different Angular configuration per environment — `production` uses
`environment.prod.ts`, `staging` uses `environment.staging.ts`, and
`deploy.yml` picks by branch. Build the wrong one and you get an app that
looks correct and talks to the other environment's database.

## First-time setup

### 1. Provision each server

Clone the repo on the box and run it as root, once per environment. It takes
the API host and the SPA host, in that order:

```bash
ssh root@148.113.192.33
git clone https://github.com/ygsorianochiukim/Cargo-Rush.git /tmp/cargo
bash /tmp/cargo/deploy/scripts/provision.sh staging \
       staging.aya-it.online staging-app.aya-it.online
bash /tmp/cargo/deploy/scripts/provision.sh production \
       api.aya-it.online app.aya-it.online
```

Both environments can share one box — they get separate directories,
databases, nginx vhosts and systemd units. Pass the same name twice
(`provision.sh staging host host`) for a same-origin setup instead; the
cookie is then scoped to that single host, which is simpler and safer.

It installs nginx, PHP 8.4 + FPM, MySQL and certbot; creates the `deploy` user
and a `cargo_staging` database; writes `shared/.env` with a generated `APP_KEY`
and database password; installs the vhost, the queue worker and the scheduler
timer; and prints what is left to do.

Override the defaults with environment variables if you need to:
`PHP_VERSION`, `DEPLOY_USER`, `DEPLOY_ROOT`.

Re-running it is safe. The two files that other things also write —
`shared/.env` and the nginx vhost — are left alone once they exist, and it
says so rather than doing it quietly. The vhost matters because `certbot`
rewrites it to add TLS; regenerating from the template would throw that away.
`FORCE_NGINX=1` regenerates anyway and keeps a timestamped backup, after which
you re-run certbot. The database and its password are likewise left alone if
the database already exists.

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

Point all four hostnames at the box, then get a certificate for each:

```bash
ssh root@148.113.192.33
certbot --nginx -d staging.aya-it.online     --redirect
certbot --nginx -d staging-app.aya-it.online --redirect
certbot --nginx -d api.aya-it.online         --redirect
certbot --nginx -d app.aya-it.online         --redirect
```

certbot rewrites each vhost to add its 443 block. Do this **before** the first
deploy: the smoke test requests both hosts over HTTPS, and
`SESSION_SECURE_COOKIE=true` means sessions would not work over plain HTTP
anyway.

### 4. Create the GitHub Environments

`Settings → Environments`, one named `staging` and one named `production`.

Secrets, per environment:

| Secret            | Value |
|-------------------|-------|
| `SSH_HOST`        | The server's hostname or IP |
| `SSH_PRIVATE_KEY` | Contents of `~/.ssh/cargo_ci_staging` — the private half, whole file including the BEGIN/END lines |
| `SSH_KNOWN_HOSTS` | Output of `ssh-keyscan -t rsa,ecdsa,ed25519 148.113.192.33` |

Variables, per environment — `staging` shown, production takes the other pair:

| Variable      | Value |
|---------------|-------|
| `SSH_USER`    | `deploy` |
| `DEPLOY_PATH` | `/var/www/cargo-rush/staging` |
| `API_URL`     | `https://staging.aya-it.online` |
| `APP_URL`     | `https://staging-app.aya-it.online` |
| `SSH_PORT`    | Only if not 22 |

`API_URL` and `APP_URL` are both needed because the smoke test checks each
host and then checks that the API actually allows the SPA's origin — a CORS
mistake leaves both hosts healthy on their own while the app is unusable.

`SSH_USER` is a variable rather than a secret on purpose. A username is not
sensitive, and GitHub redacts every secret's literal text from all log output
— so storing the value `deploy` as a secret turns `deploy/README.md` into
`***/README.md` in every run, across the whole repository. Secrets are for
things that would matter if they leaked, not for everything to do with
deployment.

`SSH_KNOWN_HOSTS` is a secret rather than an `ssh-keyscan` at deploy time on
purpose: keyscan trusts whatever answers on the night, which is not
verification.

On the `production` environment, also turn on **Required reviewers**. It is the
one thing that stops an accidental push to `main` going straight to live.

Until an environment has all six, its deploy job fails on the first step and
names what is missing. Tests and the release build still run, so a push before
the server exists tells you whether the code is good — it just has nowhere to
put it.

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

**The three cross-origin settings fail in ways that don't point at
themselves.** `SANCTUM_STATEFUL_DOMAINS` missing the SPA host gives 401 on
every authenticated request while login appears to succeed.
`SESSION_DOMAIN` too narrow gives 419 on every write, because the SPA cannot
read the XSRF cookie to echo it back. `FRONTEND_URL` wrong gives a browser
CORS error with both hosts perfectly healthy. The deploy's smoke test catches
the third; the first two only show up in a browser. After editing any of them
in `shared/.env`, run `php8.4 artisan config:cache` or the change does
nothing.

**Mail is `log` by default.** Password resets and invoice delivery are written
to `storage/logs` rather than sent until you put real SMTP credentials in
`shared/.env` and change `MAIL_MAILER`. Run `php artisan config:cache` after
editing that file, or the change will not take effect.

**Seeders never run automatically.** `release.sh` runs `migrate --force` and
nothing else. A fresh staging database is empty; seed it by hand once:

```bash
cd /var/www/cargo-rush/staging/current/CargoApi && php8.4 artisan db:seed --force
```
