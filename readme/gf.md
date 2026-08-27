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

Command names use the `namespace:command` convention (`db:run`). The space-separated
spelling also works — `gf db run` resolves to `db:run` automatically.

| Command | Replaces | Purpose |
|---|---|---|
| `gf cli <route>` | `app/cli/*.bat` + `app/cli/index.php` | Run an application CLI route |
| `gf cli:list` | — | List the application's CLI routes |
| `gf cert:generate-auth` | `scripts/create-jwt-keys.ps1` | Generate JWT signing keypairs |
| `gf chrome:install` | manual Chrome installs | Download chrome-headless-shell into srv/chrome |
| `gf chrome:update` | — | Update chrome-headless-shell to current Stable + remove old versions |
| `gf chrome:status` | — | Show whether chrome-headless-shell is installed and what version |
| `gf db:run <script.js>` | ad-hoc `mongosh "<uri with password>" script.js` | Run a mongosh script using config-managed connections |
| `gf env` | manual `Copy-Item` steps | Validate that config.json resolves; `--list` its variables; `--init` a .env skeleton |
| `gf init` | `scripts/setup.ps1` | Bootstrap a freshly scaffolded application (non-interactive) |
| `gf migrate` | — | Convert a v6 application's configuration to v7 |
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
- `gf init` runs the install automatically (`--skip-chrome` to opt out). `chrome:update` is
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

## Databases: `gf db:run`

Runs a `.js` script through `mongosh` against the application's configured connection, so scripts
stop carrying hardcoded connection strings:

```bash
gf db:run db/create-admin.js
gf db:run db/seed.js --db=reporting      # pick a database when the app has several
gf db:run db/report.js -- --quiet        # everything after -- goes to mongosh
```

Requires `mongosh` on PATH. Connection details come from `config.json`'s `mongoDatabases`; the URI
is redacted in all output.

> **`gf db:restore` was removed in v7.** It pulled another Environment's databases down to a
> workstation, which meant every developer's `.env` held production credentials. Developers get
> realistic data from the separate backup-restore workflow instead.

---

## Configuration: `gf env`

Configuration is one committed `config.json` whose environment-varying values are `%env(...)%`
references, every one of them required. `gf env` is how you find out what an Environment is missing
before the application does.

```bash
gf env            # resolve config.json against the current environment
gf env --list     # every variable it references, whether each is a secret, whether each is set
gf env --init     # write a .env skeleton from that list (--force to overwrite)
```

Validation prints the resolved type, urls, logging destination and Mongo connections (URIs
redacted), or fails naming the first unresolvable variable. `--list` and `--init` read `config.json`
without resolving anything, so they work on a fresh clone with no `.env` at all.

Because the manifest is derived from `config.json`, it cannot drift from it. `.env` also holds
variables `config.json` never sees — compose ports, CORS origins — which live in the template's
`.env.example`; `--init` does not touch an existing file.

Full reference: **[Environment variables in config](environment-variables.md)**.

---

## Project bootstrap: `gf init`

Run once after scaffolding from `gcgov/framework-app-template`:

```bash
gf init --title="Timesheet API"
```

It writes the title and guid into `config.json`, writes a `.env` skeleton, generates JWT signing
keypairs, and installs chrome-headless-shell. `--skip-env`, `--skip-keys` and `--skip-chrome` opt
out of each step; `--guid` sets the guid explicitly.

The guid is the OAuth `client_id`, so re-running `init` on an application that already has one keeps
it rather than minting a new one and invalidating every registered client.

It is deliberately **non-interactive**, which is what lets it run from a scaffolding script, a
devcontainer `postCreateCommand`, or CI. It replaces v6's `gf setup` wizard, whose prompts filled
`{placeholder}` tokens in `php.ini` and `web.config` files that no longer exist.

---

## Migrating a v6 application: `gf migrate`

Converts the configuration half of a v6 application. Run it on a clean working tree so the result
is reviewable as a diff:

```bash
gf migrate --dry-run     # show the plan
gf migrate               # apply it
```

It merges `app/config/app.json` and `app/config/environment.json` into `{root}/config.json`, turns
their environment-varying values into `%env()` references (credentials become `%env(secret:…)%`),
writes the extracted values to `.env`, and deletes the v6 IIS, batch and PowerShell files.

What it will not do is guess. `sqlDatabases` credentials, a missing `app.guid`, and the removed
`serverName` / `cookieUrl` / `phpPath` keys are reported for you to handle. It pins
`logging.destination` to `"file"` so an application's logging does not silently change on upgrade —
switch it to `"stderr"` when the application moves into a container.

It does not write a Dockerfile, choose a Zone, or move secrets into the ops repository. Those need
judgement; the companion skill covers them.

> **`gf deploy` was removed in v7.** It deployed by running `git checkout` and `composer update` on
> the server, which resolves dependencies in production at deploy time — two hosts on "the same tag"
> could be running different code. A Release is now an immutable image pinned by digest, deployed by
> a GitHub Actions workflow. See `docs/adr/0002-immutable-release-digest-pinning.md`.

---

## Tab completion

- **bash / zsh / fish** (built into symfony/console):
  `gf completion bash > /etc/bash_completion.d/gf` (see `gf completion --help` for zsh/fish).
- **PowerShell**: add to your `$PROFILE`:
  ```powershell
  vendor\bin\gf completion:powershell | Out-String | Invoke-Expression
  ```

Completion is dynamic: `gf cli <TAB>` suggests the application's actual CLI routes (with
descriptions), `gf <TAB>` completes command names.

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
Framework Services register their commands directly in `application::__construct()`, since they are
part of the framework. Name commands with a namespace prefix
(`docs:regenerate`) to avoid collisions. Discovery is fail-safe: a broken provider never takes
down gf itself (run with `-v` to see discovery errors).

Useful helpers for custom commands (all in `\gcgov\framework\cli`):

- `appContext::require()` / `appContext::locate()` — application root + config access
- `appContext->loadConfig()` / `configReferences()` — resolve config.json, or list what it references
- `configLoader::load($root)` / `loadVariantEnvironment($root, $name)` — the shared config-load pipeline (also used by `gcgovrameworknfig`)
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
| `scripts\setup.ps1` | `vendor/bin/gf init --title="…"` |
| `mongosh "mongodb://user:pass@..." db\fix.js` | `vendor/bin/gf db:run db/fix.js --env=prod` |
| `update-production.ps1` | removed — deployment is a GitHub Actions workflow (ADR 0002) |
| `Copy-Item composer-local.json composer.json` (+ 2 more) | nothing — config is committed and environment-variable driven (v7); `gf env` validates it |

Files an app can delete once migrated: `app/cli/local.bat`, `app/cli/local-debug.bat`,
`app/cli/prod.bat`, `scripts/setup.ps1`, `scripts/create-jwt-keys.ps1`,
`db/restore-live-to-local.ps1`, `update-production.ps1` — and `app/cli/index.php` once no
scheduler entry references it (gf ships its own route runner).

Reference any secrets that were hardcoded in those scripts via `%env(...)%` in the committed
the root `config.json` — the `db:*` commands and the request lifecycle both resolve
them. Keep the actual values in the process environment, Docker/Kubernetes secrets, or a
gitignored `.env` file (per-variant values for the `db:*` commands go in gitignored
See **[Environment variables in config](environment-variables.md)**
and **[Migrating a v6 app to v7](#migrating-a-v6-app-to-v7)** above.

For example, instead of a plaintext URI:

```jsonc
"uri": "%env(MONGO_URI)%"                    // fail loud if unset
"uri": "%env(trim:file:MONGO_URI_FILE)%"     // …or read a Docker secret file
```

`.env` files (`{app-root}/.env`, then `.env.local`) are loaded automatically before config is
resolved; the real process environment always wins over them.
