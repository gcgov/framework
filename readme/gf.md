# gf — the framework command line tool

`gf` is the multiplatform (Windows + Linux + macOS) command line tool that ships with
`gcgov/framework`. Every application that requires the framework gets it automatically at
`vendor/bin/gf` (Composer also generates `vendor\bin\gf.bat` on Windows) — there is nothing to
install or copy into the app.

Run it with no arguments to see everything available:

```
> vendor/bin/gf
```

Tip: add `vendor/bin` to your PATH (or use `composer exec gf`) so you can type `gf` alone.
Throughout this document `gf` means `vendor/bin/gf` (`vendor\bin\gf.bat` on Windows).

Command names use the `namespace:command` convention (`db:restore`). The space-separated
spelling also works — `gf db restore` resolves to `db:restore` automatically.

| Command | Replaces | Purpose |
|---|---|---|
| `gf cli <route>` | `app/cli/*.bat` + `app/cli/index.php` | Run an application CLI route |
| `gf cli:list` | — | List the application's CLI routes |
| `gf cert:generate-auth` | `scripts/create-jwt-keys.ps1` | Generate JWT signing keypairs |
| `gf chrome:install` | manual Chrome installs | Download chrome-headless-shell into srv/chrome |
| `gf chrome:update` | — | Update chrome-headless-shell to current Stable + remove old versions |
| `gf chrome:status` | — | Show whether chrome-headless-shell is installed and what version |
| `gf db:restore` | `db/restore-live-to-local.ps1` | Copy a source environment's mongo databases into a target environment |
| `gf db:run <script.js>` | ad-hoc `mongosh "<uri with password>" script.js` | Run a mongosh script using config-managed connections |
| `gf env [<env>]` | manual `Copy-Item` steps | List environment variants; validate that config resolves (active env or a `{env}.env` overlay) |
| `gf setup` | `scripts/setup.ps1` | Bootstrap a freshly scaffolded application |
| `gf deploy` | `update-production.ps1` | Tag-based production deployment |
| `gf completion` / `gf completion:powershell` | — | Shell tab completion |

`gf` never requires a Windows shell: everything is implemented in PHP or shells out to
cross-platform binaries (`git`, `composer`, `mongodump`/`mongorestore`/`mongosh`).

---

## Running CLI routes: `gf cli`

```
gf cli /cli/generate-shifts             # run a route
gf cli /cli/sync-outlook-calendars/asauder
gf cli                                  # same as gf cli:list
gf cli:list                             # table of CLI routes + descriptions
gf cli /cli/generate-shifts --debug     # run with Xdebug (replaces local-debug.bat)
```

- The route executes through the full framework lifecycle in a **fresh PHP process**, exactly
  like the legacy `app/cli/index.php` entry (`REQUEST_METHOD=CLI`, `REQUEST_URI=<route>`).
- **Exit codes**: `0` on success, `1` when the response status is 400+ — so Task Scheduler /
  cron can detect failures. (The legacy `.bat` entry always exited 0.)
- **Interpreter selection** (first match wins): `--php=<binary or directory>`, the `GF_PHP`
  environment variable, `phpPath` in `config.json`, the PHP running gf. Any of these may
  include trailing arguments after the binary, e.g. `C:\path\php.exe -c C:\path\php.ini`
  (quote a binary or argument that contains spaces).
- **It must be the CLI binary.** `php-cgi.exe` (what an IIS FastCGI handler mapping points at),
  `php-fpm`, and `php-win.exe` cannot run routes — they define neither `$argv`/`$argc` nor
  `STDERR`. gf swaps such a path for the `php`/`php.exe` sitting beside it and errors out with
  an explanation when there isn't one.
- The child is always started with `-dregister_argc_argv=1`, so a `php.ini` derived from
  `php.ini-production` (which sets `register_argc_argv = Off`) can't leave the route runner
  without its arguments.
- `--debug` adds `-dxdebug.mode=debug -dxdebug.start_with_request=yes` with
  `--debug-host` (default `127.0.0.1`) and `--debug-port` (default `9003`).
- `gf` locates the application root from its own install location, so it works from any
  working directory — point Task Scheduler / cron directly at it:

```
Windows Task Scheduler action:  E:\Web\...\api\vendor\bin\gf.bat
                    arguments:  cli /cli/sync-leave-balances
Linux cron:  /var/www/api/vendor/bin/gf cli /cli/sync-leave-balances
```

### Route descriptions

Give routes a human-readable description (surfaced by `cli:list` and tab completion) with the
route model's optional final parameter:

```php
$routes[] = new route( 'CLI', '/cli/generate-shifts', '\app\controllers\cli\generateShifts', 'generate',
                       description: 'Nightly rolling-horizon shift generator' );
```

---

## JWT signing keys: `gf cert:generate-auth`

```
gf cert:generate-auth            # 5 RSA-2048 keypairs -> srv/jwtCertificates + guids.json
gf cert:generate-auth --count=3 --yes
```

Uses the PHP OpenSSL extension — the `openssl` binary is not required. Prompts before
replacing existing keys (regenerating invalidates every issued JWT). `--yes` skips the prompt.

---

## Headless Chrome: `gf chrome:install` and `gf chrome:update`

```
gf chrome:install            # download the current Stable chrome-headless-shell (~100-150 MB)
gf chrome:install --force    # reinstall the current version
gf chrome:update             # move to the newest Stable + delete superseded versions
gf chrome:status             # installed? version, platform, executable path
gf chrome:status --check-latest   # also compare against the current Stable release
```

`chrome:status` exits `0` when a working installation exists and `1` otherwise, so scripts can
gate on it (`gf chrome:status && ...`). An outdated-but-working installation still exits `0` —
`--check-latest` reports "update available" without failing (it is the only part of the command
that touches the network, and a network failure there only warns).

- The current **Stable** version is discovered from the Chrome for Testing feed
  (`https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json`)
  and the build matching your platform (`win64`, `win32`, `linux64`, `mac-x64`, `mac-arm64`) is
  downloaded automatically.
- Installs into `{appRoot}/srv/chrome/{version}/chrome-headless-shell-{platform}/` with a
  `srv/chrome/installation.json` manifest recording the active version; the directory is
  git-ignored automatically. Installation is atomic — an interrupted download never leaves a
  half-installed version.
- `gf setup` runs the install automatically (`--skip-chrome` to opt out). `chrome:update` is
  idempotent and safe to run on a schedule.
- Requires the PHP **zip** extension (`extension=zip` in php.ini on Windows, `php-zip` on Linux).
- macOS note: if Gatekeeper ever blocks the binary, clear the quarantine attribute with
  `xattr -d com.apple.quarantine <path>`.

Application code uses the installed binary through the framework's chrome service — no paths in
app code:

```php
use gcgov\framework\services\chrome\chrome;

$executablePath = chrome::getExecutablePath();          // full path to chrome-headless-shell
$browser        = chrome::getBrowserFactory()->createBrowser();   // \HeadlessChromium\BrowserFactory
$page           = $browser->createPage();
$page->navigate( 'https://example.com' )->waitForNavigation();
$page->pdf()->saveToFile( '/tmp/example.pdf' );
```

Both methods throw a `serviceException` telling you to run `gf chrome:install` when no
installation exists. The `chrome-php/chrome` library is a framework dependency, so
`\HeadlessChromium\*` classes are always available to apps.

---

## Databases: `gf db:restore` and `gf db:run`

Connection strings come from the unified `{root}/config.json` (`mongoDatabases[]`) — never
hardcode credentials in scripts again. A variant name (`--from=prod`, `--env=prod`) resolves
that same `config.json` with the variables from the gitignored overlay file
`{root}/{name}.env` applied on top of your local environment (see
[Environments](#environments-gf-env) below):

```ini
# {root}/prod.env (gitignored; start from prod.env.example)
APP_TYPE=prod
MONGO_URI=mongodb+srv://user:pass@prod-cluster/
MONGO_DATABASE=app
```

```
gf db:restore                        # dump prod -> restore into the active environment (--drop)
gf db:restore --from=prod --to=local
gf db:restore --db=AppsSchedule      # only the named database(s)
gf db:restore --keep-dump --dump-dir=db/backup
```

- Source/target databases are paired by database name (falling back to the two `default`
  entries); differing names are remapped with `--nsFrom/--nsTo`.
- Restoring **into** the variant named `prod`, or into an environment whose resolved `type` is
  `prod`, is refused unless `--allow-prod`.
- A pair whose source and target resolve to the **same uri and database** is refused outright —
  that almost always means an incomplete `{name}.env` overlay silently fell back to your local
  values. Validate the overlay first with `gf env prod`.
- Requires the [MongoDB Database Tools](https://www.mongodb.com/try/download/database-tools)
  (`mongodump`, `mongorestore`) on PATH.
- The plan (with passwords redacted) is shown and confirmed before anything runs; `--yes` skips.

```
gf db:run db/create-admin.js                 # against the active config.json default db
gf db:run db/migrate.js --env=prod --db=AppsSchedule
gf db:run db/seed.js -- --quiet              # everything after -- goes to mongosh
```

Requires [mongosh](https://www.mongodb.com/try/download/shell) on PATH.

---

## Environments: `gf env`

Environment selection is **environment-variable driven**: the committed
the root `config.json` references variables with `%env(...)%`, and whichever values the
process environment (container env, Docker secrets, or `{root}/.env`) supplies *are* the
environment. There is nothing to activate or copy.

`gf env` is the validator for that model:

```
gf env              # list {root}/*.env variants + validate the ACTIVE environment
gf env prod         # resolve config.json with the prod.env overlay and validate it
```

`gf env <name>` prints the resolved summary (type, serverName, urls, databases with redacted
URIs) and exits non-zero naming the first unresolvable variable. Run it before trusting a
variant with `db:restore`/`db:run` — a variable missing from the overlay silently falls back to
your local value, so **an overlay file must define every environment-specific variable**.

### Migrating a v6 app to v7

v6's split `app/config/app.json` + `environment-{env}.json` files and the `gf env` copy step
are gone. To move an app onto v7:

1. Commit a single **`config.json` at the application root**: merge the contents of the old
   `app/config/app.json` (`app`, `email`, `settings` sections) and `app/config/environment.json`
   (everything else) into one JSON object, with every secret and every per-environment value
   referenced via `%env(...)%` — see [environment-variables.md](environment-variables.md) and
   the app template's copy. Then delete the `app/config/` directory.
2. For each old variant, create a gitignored **`{env}.env` at the application root** holding
   that environment's variable values (start from the template's `prod.env.example`); gitignore
   `/*.env`. Local values go in `{root}/.env` (from `.env.example`).
3. Delete `environment-{env}.json`, `composer-{env}.json`, and `www/web-{env}.config`; commit
   `composer.json` (and a static `www/web.config`, if the app still runs on IIS).
4. Replace `config::getAppConfig()` / `config::getEnvironmentConfig()` calls in app code with
   the flattened accessors (`config::getBasePath()`, `config::getSettings()`,
   `config::getMongoDatabases()`, …).
5. Bump `gcgov/framework` to `^v7.0`; verify with `gf env` and `gf env prod`.

---

## Project bootstrap: `gf setup`

Interactive replacement for `scripts/setup.ps1`. Run once after scaffolding a project from
`gcgov/framework-app-template` (after `composer install`): prompts for the project values,
generates the app GUID, then replaces the `{placeholder}` tokens across the project's
`.ini/.json/.php/.config/.bat/.ps1` files — including the per-environment `php.ini` files under
`srv/` (`vendor/`, `.git/`, `node_modules/` are excluded). Pressing enter skips a value and
leaves its token for a later re-run.

Setup finishes by downloading chrome-headless-shell (the `gf chrome:install` step); a failure
there — offline machine, missing php-zip — only prints a warning and never fails setup. Pass
`--skip-chrome` to skip it entirely.

---

## Deployment: `gf deploy`

Cross-platform replacement for the per-app `update-production.ps1`:

```
gf deploy                        # interactive tag picker
gf deploy --tag=v2.4.1 --yes     # non-interactive
gf deploy --no-composer
```

Steps: `git fetch/pull` → pick a tag (newest first, `--tags=N` to widen) → confirm →
`git checkout tags/<tag>` → `git submodule sync/update` → write
`version.json` (`{"version": "<tag>", "inherit": true}`) → `composer update`.
Any failing step aborts the deploy with that step's exit code. Configuration is committed
(`config.json` + `%env()` values from the server's environment), so there is no
config-activation step.

---

## Tab completion

- **bash / zsh / fish** (built into symfony/console):
  `gf completion bash > /etc/bash_completion.d/gf` (see `gf completion --help` for zsh/fish).
- **PowerShell**: add to your `$PROFILE`:
  ```powershell
  vendor\bin\gf completion:powershell | Out-String | Invoke-Expression
  ```

Completion is dynamic: `gf cli <TAB>` suggests the application's actual CLI routes (with
descriptions), `gf env <TAB>` (and `db:restore --from=<TAB>` etc.) suggests the variant overlay
files (`{root}/*.env`) present in the app.

---

## Extending gf (apps and plugins)

Add custom commands with one file — no registration beyond the class itself:

- **Application**: `app/cli/commandProvider.php` → class `\app\cli\commandProvider`
- **Framework-service plugin**: `src/cli/commandProvider.php` →
  class `\gcgov\framework\services\{name}\cli\commandProvider`

```php
<?php
namespace app\cli;

class commandProvider implements \gcgov\framework\cli\commandProvider {

	public static function getCommands(): array {
		return [
			new \app\cli\commands\importWidgetsCommand(),
		];
	}

}
```

Commands are ordinary [symfony/console](https://symfony.com/doc/current/console.html) commands.
gf discovers providers in the `\app` namespace and in every namespace the app registers via
`\app\app::registerFrameworkServiceNamespaces()`. Name plugin commands with a namespace prefix
(`docs:regenerate`) to avoid collisions. Discovery is fail-safe: a broken provider never takes
down gf itself (run with `-v` to see discovery errors).

Useful helpers for custom commands (all in `\gcgov\framework\cli`):

- `appContext::require()` / `appContext::locate()` — application root + config access
- `appContext->loadConfig($variant)` — resolve the root `config.json` (with the `{variant}.env` overlay when a variant is named)
- `dotEnvLoader::parseFile($path)` — parse a dotenv file to an array without touching the process env
- `mongoTools::findBinary()/redactUri()/uriWithDatabase()`
- `phpProcess::findPhpBinary()/requiredIniFlags()/xdebugFlags()`
- throw `cliException` for user-facing errors

---

## Migrating an existing app to gf

| Before (per-app copy) | After |
|---|---|
| `app\cli\local.bat /cli/x` | `vendor\bin\gf cli /cli/x` |
| `app\cli\prod.bat /cli/x` (Task Scheduler) | `vendor\bin\gf.bat cli /cli/x` |
| `app\cli\local-debug.bat /cli/x` | `vendor/bin/gf cli /cli/x --debug` |
| `scripts\create-jwt-keys.ps1` | `vendor/bin/gf cert:generate-auth` |
| `scripts\setup.ps1` | `vendor/bin/gf setup` |
| `db\restore-live-to-local.ps1` | `vendor/bin/gf db:restore --from=prod` |
| `mongosh "mongodb://user:pass@..." db\fix.js` | `vendor/bin/gf db:run db/fix.js --env=prod` |
| `update-production.ps1` | `vendor/bin/gf deploy` |
| `Copy-Item composer-local.json composer.json` (+ 2 more) | nothing — config is committed and environment-variable driven (v7); `gf env` validates it |

Files an app can delete once migrated: `app/cli/local.bat`, `app/cli/local-debug.bat`,
`app/cli/prod.bat`, `scripts/setup.ps1`, `scripts/create-jwt-keys.ps1`,
`db/restore-live-to-local.ps1`, `update-production.ps1` — and `app/cli/index.php` once no
scheduler entry references it (gf ships its own route runner).

Reference any secrets that were hardcoded in those scripts via `%env(...)%` in the committed
the root `config.json` — the `db:*` commands and the request lifecycle both resolve
them. Keep the actual values in the process environment, Docker/Kubernetes secrets, or a
gitignored `.env` file (per-variant values for the `db:*` commands go in gitignored
`{root}/{env}.env` overlays). See **[Environment variables in config](environment-variables.md)**
and **[Migrating a v6 app to v7](#migrating-a-v6-app-to-v7)** above.

For example, instead of a plaintext URI:

```jsonc
"uri": "%env(MONGO_URI)%"                    // fail loud if unset
"uri": "%env(trim:file:MONGO_URI_FILE)%"     // …or read a Docker secret file
```

`.env` files (`{app-root}/.env`, then `.env.local`) are loaded automatically before config is
resolved; the real process environment always wins over them.
