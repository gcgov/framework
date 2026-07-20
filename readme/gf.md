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
| `gf db:restore` | `db/restore-live-to-local.ps1` | Copy a source environment's mongo databases into a target environment |
| `gf db:run <script.js>` | ad-hoc `mongosh "<uri with password>" script.js` | Run a mongosh script using config-managed connections |
| `gf env <env>` | manual `Copy-Item` steps | Activate an environment's config file variants |
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
  environment variable, `phpPath` in `environment.json`, the PHP running gf.
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
```

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

Connection strings come from the environment variant config files
(`app/config/environment-{env}.json` → `mongoDatabases[]`) — never hardcode credentials in
scripts again.

```
gf db:restore                        # dump prod -> restore into the active environment.json (--drop)
gf db:restore --from=prod --to=local
gf db:restore --db=AppsSchedule      # only the named database(s)
gf db:restore --keep-dump --dump-dir=db/backup
```

- Source/target databases are paired by database name (falling back to the two `default`
  entries); differing names are remapped with `--nsFrom/--nsTo`.
- Restoring **into** an environment whose `type` is `prod` is refused unless `--allow-prod`.
- Requires the [MongoDB Database Tools](https://www.mongodb.com/try/download/database-tools)
  (`mongodump`, `mongorestore`) on PATH.
- The plan (with passwords redacted) is shown and confirmed before anything runs; `--yes` skips.

```
gf db:run db/create-admin.js                 # against the active environment.json default db
gf db:run db/migrate.js --env=prod --db=AppsSchedule
gf db:run db/seed.js -- --quiet              # everything after -- goes to mongosh
```

Requires [mongosh](https://www.mongodb.com/try/download/shell) on PATH.

---

## Environments: `gf env`

```
gf env local        # environment-local.json -> environment.json,
                    # composer-local.json    -> composer.json,
                    # www/web-local.config   -> www/web.config
gf env prod --dry-run
```

Missing variant files are skipped with a note; it is an error only if no variant exists at all.

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
gf deploy                        # interactive tag picker, env=prod
gf deploy --tag=v2.4.1 --yes     # non-interactive
gf deploy --env=local --no-composer
```

Steps: `git fetch/pull` → pick a tag (newest first, `--tags=N` to widen) → confirm →
`git checkout tags/<tag>` → `git submodule sync/update` → `gf env <env>` copy step → write
`version.json` (`{"version": "<tag>", "inherit": true}`) → `composer update`.
Any failing step aborts the deploy with that step's exit code.

---

## Tab completion

- **bash / zsh / fish** (built into symfony/console):
  `gf completion bash > /etc/bash_completion.d/gf` (see `gf completion --help` for zsh/fish).
- **PowerShell**: add to your `$PROFILE`:
  ```powershell
  vendor\bin\gf completion:powershell | Out-String | Invoke-Expression
  ```

Completion is dynamic: `gf cli <TAB>` suggests the application's actual CLI routes (with
descriptions), `gf env <TAB>` suggests the environment variants present in `app/config/`.

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
- `appContext->loadEnvironmentConfig($variant)` — parse an environment variant file
- `environmentFiles::apply($root, $env)` — the `gf env` copy step
- `mongoTools::findBinary()/redactUri()/uriWithDatabase()`
- `phpProcess::findPhpBinary()/xdebugFlags()`
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
| `Copy-Item composer-local.json composer.json` (+ 2 more) | `vendor/bin/gf env local` |

Files an app can delete once migrated: `app/cli/local.bat`, `app/cli/local-debug.bat`,
`app/cli/prod.bat`, `scripts/setup.ps1`, `scripts/create-jwt-keys.ps1`,
`db/restore-live-to-local.ps1`, `update-production.ps1` — and `app/cli/index.php` once no
scheduler entry references it (gf ships its own route runner).

Move any secrets that were hardcoded in those scripts into the environment variant config
files (`environment-{env}.json`), which the `db:*` commands read.
