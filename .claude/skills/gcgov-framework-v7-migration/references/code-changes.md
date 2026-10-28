# Code changes, v6 to v7

The authority is the framework itself: `vendor/gcgov/framework/CLAUDE.md`, `CONTEXT.md`,
`readme/gf.md` and `readme/environment-variables.md` after the upgrade. This file is the
migration checklist, with lab-api's diff (`main` to `v7`) as the example.

## Contents

1. Composer
2. config.json after `gf migrate`
3. Application code
4. Tests
5. Files to delete

## 1. Composer

v6 kept three manifests (`composer-local.json`, `composer-prod.json`, `composer-ci.json`), copied one
over a gitignored `composer.json`, and resolved on the server at deploy time. v7 has one committed
manifest and one committed lock. The image installs from the lock, so two hosts on one Release
run the same code.

```jsonc
{
  "name": "gcgov/<slug>",
  "require": {
    "php": ">=8.4",
    "ext-mongodb": "*",
    "ext-zip": "*",                       // only if the app writes xlsx/zip (PhpSpreadsheet, ZipArchive)
    "gcgov/framework": "^7.0"
    // keep the app's own packages; remove every gcgov/framework-service-* package
  },
  "config": { "platform": { "php": "8.4.26" } },   // the newest 8.4 patch; the image floats above it
  "autoload": { "psr-4": { "app\\": "app/" } }
}
```

- Remove `gcgov/framework-service-auth-oauth-server`, `-auth-ms-front`, `-user-crud`,
  `-documentation` and `-gcgov-cron-monitor`. The framework declares a `conflict` with all five.
- Remove `repositories` entries that point at `../framework` or a Windows path.
- Before v7.0.0 is tagged, `^7.0@RC` resolves the release candidate. After, use `^7.0`. The
  template's `scripts/adopt-framework-release.sh '^7.0'` does the swap and re-locks.
- `.gitignore`: delete the `composer.json`, `composer.lock`, `www/web.config`,
  `app/config/environment.json` and `version.json` lines. Add `.env`, `.env.local`, `/secrets/`.
- No PHP 8.4 on the machine: run composer in a container.

  ```bash
  docker run --rm -v "$PWD":/app -w /app composer:2 update --no-interaction --ignore-platform-req=ext-mongodb
  ```

  Ignore extensions by name. Never use the blanket `--ignore-platform-reqs`: it also drops the
  `config.platform.php` pin and locks packages the image cannot install.

## 2. config.json after `gf migrate`

`gf migrate` writes a correct starting point. These are the decisions it leaves to you.

| Item | Do this | Why |
|---|---|---|
| `logging.destination` | Set `"stderr"`. | `gf migrate` writes `"file"` to keep IIS behaviour. A container's files vanish on each deploy; the journal keeps stderr. |
| `mongoDatabases[0].logging` | `false` | On, it writes a line per database operation into the shared journal. Turn it on to trace a problem. |
| `settings.useSession` | Delete it. | v7 removed it. |
| `setBlockNewUsers(true, $roles)` in `app.php` | `"services": { "auth": { "provider": "oauth", "blockNewUsers": true, "defaultNewUserRoles": [] } }` with the roles the call passed, as literals | The singletons are gone. Lost, any successful sign-in can create an account. |
| `setAuthorizeUrlParameters(...)` | `services.auth.oauth.authorizeUrlParameters` | Same. |
| `jwtAuth.keyPath` | `"%env(APP_JWT_KEY_PATH)%"` | Keys are gitignored, so no image holds them. The Zone mounts them at `/run/secrets/<slug>/jwt/`. |
| `jwtAuth.tokenIssuedBy`, `tokenPermittedFor` | Delete both, unless an outside client checks `iss` or `aud`. | They derive from `rootUrl` and `basePath`. See `urls-and-names.md` §3. |
| `MICROSOFT_CLIENT_ID`, `MICROSOFT_TENANT`, `fromAddress` | Make each a literal again if it is the same in every Environment. | Every Config Reference is required. A value that never varies and is not a Secret is a literal, not one more variable to supply. lab-api kept these literal. |
| `microsoft.sharepointSiteId` and other unknown keys | Run `gf env`. Delete a key it rejects, after a `grep` shows no code reads it. | |
| `sqlDatabases` | Convert by hand: `dsn` literal or reference, each password `%env(secret:SQL_<NAME>_PASSWORD)%`. | `gf migrate` leaves credentials it cannot name. |
| `payjunction.*` | `gf migrate` extracts them. Check the names. | |
| `appDictionary.cronMonitorUrl` | `gf migrate` moves it to `cronMonitor.url`. Update the code that read it. | |
| `app.guid` | Keep it unchanged. | It is the OAuth `client_id`. A new value invalidates every registered client. |

Then check the result:

```bash
vendor/bin/gf env --list    # every Config Reference, and which are Secrets
```

Then compare the list with lab-api's: `APP_TYPE`, `APP_ROOT_URL`, `APP_BASE_PATH`,
`APP_REDIRECT_AFTER_LOGIN`, `APP_REDIRECT_AFTER_LOGOUT`, `APP_JWT_KEY_PATH`, `MONGO_DATABASE`,
`MONGO_URI` (Secret), plus what the app adds (`MICROSOFT_CLIENT_SECRET` (Secret),
`MICROSOFT_DRIVE_ID`, and so on). Every name on that list must appear in three places: `.env`,
the Ops Repo `compose.yaml` (as a value or a `_FILE`), and CI's `config` job.

### The `.env` that `gf migrate` writes

It holds the values from the `environment.json` it read, credentials included. That is why step 4
copies the local variant first. Check that `.env` is gitignored before the first commit. Then
edit it for the local stack:

```bash
APP_BASE_PATH=/api
APP_ROOT_URL=http://localhost:8080
APP_JWT_KEY_PATH=srv/jwtCertificates/
MONGO_URI=mongodb://localhost:27017/?directConnection=true
```

## 3. Application code

### `app/app.php`

```php
// v6
use gcgov\framework\services\authoauth\oauthConfig;
#[OA\Server(url:'https://internal-apps.garrettcountymd.gov/lab/api/')]
final class app implements \gcgov\framework\interfaces\app {
    public function registerFrameworkServiceNamespaces(): array {
        oauthConfig::getInstance()->setBlockNewUsers( true, constants::DEFAULT_ROLES );
        return [ '\gcgov\framework\services\documentation', '\gcgov\framework\services\usercrud', '\gcgov\framework\services\authoauth' ];
    }
}

// v7: only the lifecycle hooks remain. Keep the class: config derives every path from its file.
#[OA\Server(url:'/api/')]
final class app implements \gcgov\framework\interfaces\app {
    public static function _before(): void {}
    public static function _after(): void {}
}
```

### `app/router.php`

```php
// v6
class router implements \gcgov\framework\interfaces\router {
    public function getRoutes(): array {
        $b = config::getEnvironmentConfig()->getBasePath();
        ...

// v7
class router implements \gcgov\framework\interfaces\appRouter {
    public function getRoutes(): array {
        // '' at a domain root, '/api' here. getBasePath() returns '/' at a root, giving '//x'.
        $b = config::getRoutePrefix();
        ...
    }

    // false: services.auth establishes identity. true only if authentication() really
    // authenticates; a true here with the scaffolded `return true` opens every route.
    public function providesAuthentication(): bool {
        return false;
    }
```

A v6 router with `getRunFrameworkServiceRouteAuthentication()` must also implement
`\gcgov\framework\interfaces\router\skipsServiceAuthentication`, and the method takes
`\gcgov\framework\models\routeHandler $routeHandler`. Any route it opts out that declares
`requiredRoles` must call `request::getAuthUser()->setFromUser($user)`, because v7 enforces roles
in the framework router after every guard.

### Configuration reads

The v6 calls still work through deprecated pass-throughs. Replace them, so PHPStan and the IDE
stop warning and the next major does not break:

| v6 | v7 |
|---|---|
| `config::getEnvironmentConfig()->getBasePath()` | `config::getBasePath()`, or `getRoutePrefix()` for a route |
| `config::getEnvironmentConfig()->mongoDatabases` | `config::getMongoDatabases()` |
| `config::getEnvironmentConfig()->microsoft` | `config::getMicrosoft()` |
| `config::getEnvironmentConfig()->appDictionary` | `config::getAppDictionary()` |
| `config::getEnvironmentConfig()->isLocal()` | `config::isLocal()` |
| `config::getAppConfig()->app / ->email / ->settings` | `config::getApp()` / `getEmail()` / `getSettings()` |
| `config::getConfigDir()` | nothing: `app/config` no longer exists |
| `\gcgov\framework\services\authoauth\...`, `...\usercrud\...` | `\gcgov\framework\services\auth\...`, `...\userCrud\...` |
| `$environmentConfig->serverName`, `cookieUrl`, `phpPath` | removed; delete the read |

### `www/index.php`

```php
<?php
//__DIR__: PHP-FPM's working directory is not the script's directory.
include_once(__DIR__ . '/../vendor/autoload.php');

$framework = new \gcgov\framework\framework();
echo $framework->runApp();
```

Delete `www/theme/api/templates/template.wrapper.php` if nothing renders it. It is a dead v6 view
wrapper, and a static pass-through would serve its source as text.

### Behaviour the container changes

- **Files.** The image is read-only in practice. Only `srv/tmp` and `logs` are writable, and a
  deploy discards both. Code that keeps state on disk (uploads, generated files kept for later,
  file sessions) must move it to GridFS or another store. List each case in the document.
- **Logs.** `services\log` writes JSON lines to stderr. Nothing reads `logs/*.log` any more.
- **MongoDB must be a replica set.** Every `save()` and `delete()` opens a transaction. A
  standalone serves reads and fails every write. The local stack runs a one-member set. Ask the
  operator to confirm the production cluster is a replica set (`rs.status()`).
- **The scheduler.** `app/cli/prod.bat` under Windows Task Scheduler has no direct v7 equivalent.
  See the document's "Scheduled CLI Routes" section.

## 4. Tests

```php
// tests/bootstrap.php, v7
$config           = new \gcgov\framework\models\unifiedConfig();
$config->type     = 'local';
$config->basePath = '/api';
$config->logging->destination = \gcgov\framework\models\config\environment\logging::DESTINATION_FILE;
$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'unifiedConfig' );
$prop->setValue( null, $config );
```

- Replace tests of `registerFrameworkServiceNamespaces()` with tests on the loaded config:
  `configLoader::load($root)->services`. lab-api's `tests/Unit/ConfigFilesTest.php` is the model.
  It also pins the replica set, the container-pinned variables and the distinct CORS origins.
- Change "router implements `interfaces\router`" to `interfaces\appRouter`, and assert
  `providesAuthentication()` is `false`.
- Integration tests need a replica-set Mongo. CI starts `mongo:7 --replSet rs0` with
  `docker run`, because a `services:` container cannot pass the flag.

## 5. Files to delete

`gf migrate` deletes most of these. Delete the rest by hand.

| File | Replaced by |
|---|---|
| `app/config/app.json`, `environment.json`, `environment-*.json` | `config.json` and `.env` |
| `composer-local.json`, `composer-prod.json`, `composer-ci.json` | one `composer.json` + `composer.lock` |
| `www/web.config`, `web-local.config`, `web-prod.config` | `docker/nginx/default.conf.template` |
| `srv/app.*/php.ini`, `srv/app.*-cli/php.ini` | `docker/php/conf.d/app.ini`, `dev.ini` |
| `app/cli/local.bat`, `local-debug.bat`, `prod.bat` | `vendor/bin/gf cli /cli/x` (`--debug` for Xdebug) |
| `update-production.ps1` | `.github/workflows/release.yml` |
| `scripts/setup.ps1`, `scripts/create-jwt-keys.ps1` | `gf env --init`, `gf cert:generate-auth` |
| `db/restore-live-to-local.ps1` | `docker compose run --rm mongo-restore` from `db/backup/` |

Keep `app/cli/index.php` until no scheduler on the v6 server calls it.
