# The container, the local stack, CI and the release workflow

## Contents

1. Where the files come from
2. Adjust the files from the inventory
3. CI
4. The release workflow
5. Docs in the application repository

## 1. Where the files come from

Copy from `gcgov/framework-app-template`, the canonical v7 starter. Use its `v7` branch until v7
merges to `main`. It is public, so a shallow clone works anywhere:

```bash
git clone --depth 1 --branch v7 https://github.com/gcgov/framework-app-template.git /tmp/fat
```

| Copy | Purpose |
|---|---|
| `Dockerfile` | Targets `base`, `vendor`, `dev`, `php`, `nginx`. `build.yml` builds `php` and `nginx`. |
| `.dockerignore` | Keeps `.env`, `vendor`, keys and backups out of the build context. |
| `docker/nginx/default.conf.template` | Replaces `web.config`: front controller, CORS, trailing slash, forwarded scheme. |
| `docker/php/conf.d/app.ini`, `dev.ini` | Replace the four `srv/app.*/php.ini` files. |
| `docker/php/php-fpm.d/zz-app.conf` | The FPM pool: non-root, stderr, `clear_env = no`. |
| `docker-compose.yml` | The local stack: nginx, php, one-member Mongo replica set, `mongo-restore`. |
| `docker/mongodb/restore.sh`, `db/backup/.gitignore`, `db/backup/README.md` | Restore a `mongodump` into the local database. |
| `.env.example` | What compose interpolates. `gf env --init` appends the app's own variables. |
| `DOCKER.md`, `LOCAL-DEVELOPMENT.md` | Rewrite for the app. lab-api's versions show the depth to aim for. |

Never copy a file into the Ops Repo from here. Production topology lives in `gcgov/deploy`.

## 2. Adjust the files from the inventory

Each row is a fact from `inventory.py` and the change it calls for. lab-api's `v7` branch shows
each change made for a real app.

| Inventory fact | Change |
|---|---|
| Base path is `/api` (always) | `Dockerfile` nginx stage: `ENV HEALTH_URL="http://127.0.0.1/api/health"`. `docker-compose.yml` nginx healthcheck: the same URL. Add `listen [::]:80;` beside `listen 80;` in the nginx template. |
| CORS origins in `web-prod.config` | One `CORS_ORIGIN_<NAME>` per origin, translated to v7 hosts (`urls-and-names.md` §3). Name it in five places: the nginx template's `map`, `docker-compose.yml`, `.env.example`, CI's nginx lint job, and the Ops Repo `compose.yaml`. The template has three (`APP`, `FRONTEND`, `SWAGGER`); lab-api added `DMR`. |
| Headless Chrome (`services\chrome`, `controllerPdfResponse`, `HeadlessChromium`) | Pin `FROM php:8.4-fpm-bookworm`. Add the `chrome` stage that runs `vendor/bin/gf chrome:install`, the shared libraries in the `dev` and `php` stages, and `COPY --from=chrome .../srv/chrome ./srv/chrome`. Add `srv/chrome/*` to `.dockerignore` and a `/var/www/app/srv/chrome` anonymous volume to the php service in `docker-compose.yml`. Copy all of it from lab-api's `Dockerfile`. Raise `fastcgi_read_timeout` to `300` to match `max_execution_time`. |
| `PhpSpreadsheet`, `ZipArchive` | `ext-zip` in `composer.json` and CI. The template's base stage already builds `zip`. |
| `imagick` | Add `libmagickwand-dev`, `pecl install imagick`, `docker-php-ext-enable imagick` to the base stage. |
| `gd` functions | Add `libfreetype6-dev libjpeg62-turbo-dev libpng-dev` and `gd` to `docker-php-ext-install`, as lab-api did. |
| `pdo_sqlsrv` in prod `php.ini` | The Microsoft ODBC driver and `pecl install pdo_sqlsrv`. This is not in any template; flag it as an open item for review. |
| Upload limits in `php.ini` or `web.config` | `upload_max_filesize`, `post_max_size` in `app.ini` must be at least nginx `client_max_body_size`. |
| `max_execution_time` above 30 | Carry it to `app.ini` and to nginx `fastcgi_read_timeout`. |
| Static files under `www/theme` | Keep the template's `location ~ ^/(theme/|favicon\.ico)` block. With none, delete that block and the dead `template.wrapper.php`; a pass-through matches before the `\.php$` deny. |
| No static files | lab-api's nginx template has no static location at all. |
| Database name | `MONGO_DATABASE` in `.env.example` comment and `LOCAL-DEVELOPMENT.md`. |

### The two variables that differ by side

`.env` is read by the host `gf` CLI and by the php container. Two values cannot be right on both
sides, so `docker-compose.yml` pins the container's value under `environment:`, which beats
`env_file:`. The template already does this for `APP_JWT_KEY_PATH` and `MONGO_URI`. Keep both
pins. Losing one is silent: the host's value resolves inside the container and points nowhere.

## 3. CI

Start from the template's `.github/workflows/ci.yml`. Then:

- Delete every step that checks out `gcgov/framework` or a `framework-service-*` sibling, and
  the `cp composer-ci.json composer.json` step.
- `PHP_VERSION: '8.4'`. Extensions: `mongodb, sodium, fileinfo, pdo, zip` plus what the app needs.
- `composer validate --no-check-publish --no-check-all` before every install. A stale lock
  otherwise installs the wrong framework quietly.
- Keep the app's own jobs (code style, coverage, integration). Integration tests need
  `docker run -d --name mongodb -p 127.0.0.1:27017:27017 mongo:7 --replSet rs0 --bind_ip_all`.
- Add a `config` job that runs `vendor/bin/gf env` with a dummy value for every Config
  Reference. It proves `config.json` resolves through the real CLI.
- Add the nginx lint job with every `CORS_ORIGIN_*` set and `--add-host=php:127.0.0.1`.
- Add a `docker-build` job for the `php` and `nginx` targets. lab-api's job also asserts that no
  key material and no `.env` reached the image, that the image runs as `www-data`, and that Chrome
  starts. Copy the assertions that apply.

## 4. The release workflow

Copy `assets/app/release.yml` to `.github/workflows/release.yml` and fill in the slug and the Zone.

- A push to `main` builds and publishes the images. A `v*` tag also requests a deploy.
- The **calling job** declares `permissions: contents: read, packages: write`. A called workflow
  cannot widen the caller's token, so without it the GHCR push fails after the whole build.
- `OPS_DISPATCH_TOKEN` is a repository secret the operator adds. Without it a tag builds and
  publishes, and the dispatch step fails with `OPS_DISPATCH_TOKEN is not set`.
- `kind: api` builds `php` and `nginx`.

## 5. Docs in the application repository

- `README.md`: replace the v6 "copy composer-local.json" and `update-production.ps1` text with the
  v7 setup (`composer install`, `gf env --init`, `docker compose up`), and point at `DOCKER.md`.
- `DOCKER.md`: the images, the Config References table, which are Secrets, health routes, logs,
  and "production topology lives in gcgov/deploy".
- `LOCAL-DEVELOPMENT.md`: bootstrap to a signed-in request. Include `gf user:create` for the
  Bootstrap User and the `mongo-restore` path.
- A note that the v6 credentials are in the git history and were rotated (or must be).
