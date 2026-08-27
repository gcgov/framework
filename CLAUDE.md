# CLAUDE.md — gcgov/framework

Guidance for Claude when working **on this framework** or **on any application built on it**.
This file is the fast path to a correct mental model. For exhaustive reference, see `README.md` and the
`readme/` directory (especially `readme/mongodb.md`).

---

## 1. What this is

`gcgov/framework` is a small, opinionated PHP 8.4+ framework for building **REST APIs** (and optionally
SSR apps) for Garrett County Government. Composer package name: `gcgov/framework`, PSR-4 root
`gcgov\framework\` → `src/`.

A full API with Microsoft OAuth authentication, user CRUD, and OpenAPI docs can be assembled with **almost
no custom code** by enabling Framework Services in config.json (see §12). The framework's standout feature is its
**MongoDB document-modeling system** (`\gcgov\framework\services\mongodb`), which is where most of the code
and most of the complexity lives (§7).

The framework does **not** ship an application. Apps live in their own repo and depend on this package.
The canonical starter is `gcgov/framework-app-template` (API) plus `gcgov/framework-frontend-template` (UI).
If you need to see a real consuming app to answer a question accurately, **ask the user for access to a sample
project** rather than guessing.

---

## 2. Repo layout (this package)

```
bin/gf                     # entry script for the gf CLI (composer bin → vendor/bin/gf in apps)
src/
├── framework.php          # entry point: runApp() drives the whole lifecycle
├── router.php             # framework router: merges service + app routes, runs auth guards
├── renderer.php           # invokes the matched controller, serializes the response
├── config.php             # static config + path resolver (app dir, srv dir, the unified {root}/config.json)
├── cli/                   # the gf command line tool (§16): application, appContext, commands/*
├── interfaces/            # contracts an app must implement (app, router, render, controller, auth\user, ...)
├── models/                # route, routeHandler, controller*Response, authUser, config/* DTOs, customConstraints
├── services/              # log, guid, http, formatting, request, jwtAuth, pdodb, microsoft(deprecated), mongodb/*
├── exceptions/            # configException, routeException, controllerException, modelException, serviceException, ...
└── traits/                # userTrait
readme/                    # long-form docs (mongodb.md is the authoritative Mongo reference; gf.md for the CLI)
tests/Unit/                # PHPUnit tests mirroring src/ (see §14)
phpstan.neon.dist          # PHPStan level config; phpstan-stubs/ holds stubs
```

### Naming conventions — IMPORTANT, follow exactly
- **Class names are lowercase**: `class inspection`, `class user`, `class router`, `class app`,
  `controllerDataResponse`. This is deliberate and pervasive. File name == class name (`inspection.php`).
- App code lives under namespace `\app` mapped to the app's `/app` directory. Framework code is
  `\gcgov\framework\...`. Framework Services are `\gcgov\framework\services\<name>\...`.
- Do not "modernize" to StudlyCase class names — you will break PSR-4 autoloading and every reference.

---

## 3. The application contract (what a consuming app must provide)

An app that runs a full request lifecycle must supply, in its `/app` directory:

| File | Class | Must implement |
|------|-------|----------------|
| `/app/app.php` | `\app\app` | `\gcgov\framework\interfaces\app` |
| `/app/router.php` | `\app\router` | `\gcgov\framework\interfaces\appRouter` |
| `/app/renderer.php` | `\app\renderer` | `\gcgov\framework\interfaces\render` |
| `/app/controllers/*.php` | e.g. `\app\controllers\widget` | `\gcgov\framework\interfaces\controller` |

Required config file (missing it throws `configException` at request time):
- `/config.json` — the unified configuration at the application ROOT (v7 merge of the former
  `app/config/app.json` + `app/config/environment.json`), with secrets/per-env values via `%env(...)%`.

Typical app tree (scaffolding template adds more — `srv/`, `db/`, `docker/`, `Dockerfile`, etc.):
```
/api
├── config.json                  # unified config (committed; every %env(...) ref is REQUIRED)
├── .env                         # gitignored local values (generate with `gf env --init`)
├── app/{app,router,renderer,constants}.php
│   ├── cli/index.php            # CLI entry
│   ├── controllers/{name}.php
│   └── models/{name}.php
└── www/index.php                # HTTP entry (web root)
```

### Entry points
HTTP (`/www/index.php`) — web server rewrites all app URLs here, **preserving the original path in
`REQUEST_URI`**:
```php
include_once('../vendor/autoload.php');
$framework = new \gcgov\framework\framework();
echo $framework->runApp();
```
CLI (`/app/cli/index.php`) — sets `REQUEST_METHOD='CLI'` and `REQUEST_URI=$argv[1]`, then calls
`runApp()`. CLI routes use HTTP method `'CLI'` and **do not support authentication**.

---

## 4. Request lifecycle (exact order)

`framework::runApp()` (`src/framework.php`) runs this sequence. The `_before`/`_after` methods are **static**
hooks defined by the `lifecycle\before` / `lifecycle\after` interfaces:

```
www/index.php
 app::_before()
 new app()                                # no longer asked which services to load
 router::_before()
 new framework\router()                   # health, then each service enabled in config.json's
                                          # `services` section, then \app\router
 framework\router->route()                # FastRoute dispatch + auth guards → routeHandler (or routeException)
 router::_after()
 renderer::_before()
 new framework\renderer()
 renderer->render(routeHandler|routeException|null)   # ↓ drives the controller lifecycle
   controller::_before()          (static)
   new controller() / newInstanceWithoutConstructor() for static methods
   controller->{routeMethod}(...routeArgs)   # returns a controllerResponse
   controller::_after()           (static)
 renderer::_after()
 app::_after()
```

Rules a controller method must obey:
- **Always return a `controllerResponse` subtype (§6). Never `die()`/`exit`** — it skips the rest of the
  lifecycle. (The documentation service's `yaml()` is the one deliberate exception.)
- Route method parameters are bound positionally from the URL pattern placeholders.

---

## 5. Routing

`\app\router::getRoutes()` returns `\gcgov\framework\models\route[]`. Service routers contribute routes too;
the framework merges **service routes first, then app routes** (`framework\router::getRoutes()`).

```php
new \gcgov\framework\models\route(
    string|array $httpMethod,   // 'GET' | ['GET','POST'] | 'CLI'
    string       $route,        // FastRoute pattern, e.g. 'structure/{_id}'  (nikic/fast-route syntax)
    string       $class,        // '\app\controllers\structure'
    string       $method,       // 'getOne'  — must accept the route placeholders as params
    bool         $authentication = false,
    array        $requiredRoles = [],          // e.g. [ constants::ROLE_STRUCTURE_READ ]
    bool         $allowShortLivedUrlTokens = false, // permit ?fileAccessToken=... instead of Authorization header
    string       $description = ''          // optional; shown by `gf cli:list` + shell completion
);
```
Example (from `readme/router.php.md`):
```php
$routes[] = new route('GET',    'structure/{_id}', '\app\controllers\structure', 'getOne', true, [constants::ROLE_STRUCTURE_READ]);
$routes[] = new route('POST',   'structure/{_id}', '\app\controllers\structure', 'save',   true, [constants::ROLE_STRUCTURE_READ, constants::ROLE_STRUCTURE_WRITE]);
$routes[] = new route('CLI',    '/cli/cleanup',    '\app\controllers\cli\import','cleanup',false);
```
If the app is not served at the domain root, prepend a base path (commonly
`config::getBasePath()`, which is what service routers use).

### Authentication guard flow (`framework\router::route()`)
For a matched route with `authentication === true`:
1. `\app\router::authentication($routeHandler)` runs **first** (your custom checks). Return `false` → 401.
2. Then **each enabled service router's** `authentication()` runs — unless `\app\router` implements
   `\gcgov\framework\interfaces\router\skipsServiceAuthentication` and returns `false` for that route.
3. The auth service validates the JWT from the `Authorization: Bearer …` header
   (or `?fileAccessToken=` when `allowShortLivedUrlTokens`), populate the request-scoped `authUser`, and
   enforce `requiredRoles` (missing header → 401, missing role → 403).

Routes with `authentication === false` skip all of this. A `routeException` thrown anywhere in this flow
becomes the HTTP error response.

**The framework refuses to boot** if any route sets `authentication: true` while no auth service is
enabled and `\app\router::providesAuthentication()` returns `false` — such routes look protected and are
open to anyone, because the scaffolded `authentication()` returns `true`.

---

## 6. Controllers & response types

Controllers implement `\gcgov\framework\interfaces\controller` (which only requires static `_before()`/
`_after()`). A route method returns one of these (`src/models/`):

| Response class | Use for |
|----------------|---------|
| `controllerDataResponse($data, $headers=[])` | JSON (default) or `text/plain`. `setContentType()` validates against `SupportedContentTypes`. |
| `controllerPagedDataResponse($dbGetResult)` | Wraps a paged Mongo result; auto-adds `X-Page`, `X-Count`, `X-Limit`, `X-Page-Count`, `X-Total-Count` headers. |
| `controllerFileResponse` | Stream a file from disk. |
| `controllerFileBase64EncodedContentResponse` | Return base64 content as a file download/inline. |
| `controllerViewResponse($viewFile, $vars)` | SSR: renders a PHP view; `$vars` keys become local variables. |

Set status with `$response->setHttpStatus(204)`. `204` emits `Content-Length: 0` and no body. File responses
honor `?download` (attachment vs inline). To add a new response shape you must add both the model **and** a
matching branch in `framework\renderer::render()`.

### Error handling contract
- Models throw `modelException` (and `modelDocumentNotFoundException` for not-found).
- **Controllers catch `modelException` and rethrow as `controllerException`** with an HTTP status code
  (this is the standard pattern — see the user-crud controller in §12). The renderer maps exceptions to
  responses via `\app\renderer::process{Model,Controller,Route,SystemError}Exception()`. The app's renderer
  decides the JSON error shape (template default: `{error, message, status}`).
- Exception → status: `routeException`/`controllerException`/`modelException` carry a code used as the HTTP
  status; uncaught `\Throwable` → 500.

---

## 7. MongoDB service — the core of the framework

`\gcgov\framework\services\mongodb`. **Read `readme/mongodb.md` for the full reference** — this is a summary
of the parts you need most. You model documents by extending two base classes:

- **`\gcgov\framework\services\mongodb\model`** — a top-level collection document. **Every model MUST declare
  `public \MongoDB\BSON\ObjectId $_id;`**.
- **`\gcgov\framework\services\mongodb\embeddable`** — a nested document embedded inside models; **never stored
  in its own collection**. Give it an `$_id` if it will live in an array.

Class layering (know this when debugging Mongo behavior):
1. `embeddable` — BSON/JSON (de)serialization, typemaps, `_meta`, validation helpers.
2. `dispatcher` — propagates embedded copies across collections + cascade delete.
3. `factory` — the static data-access/persistence API.
4. `model` — base class your collection models extend (adds `_getCollectionName`, `_getHumanName`, indexes).

### Defining a model
```php
final class inspection extends \gcgov\framework\services\mongodb\model {
    const _COLLECTION   = 'inspection';   // defaults to the class name if omitted
    const _HUMAN        = 'inspection';
    const _HUMAN_PLURAL = 'inspections';

    #[label('Id')]
    public \MongoDB\BSON\ObjectId $_id;

    #[label('Inspection Number')]
    #[autoIncrement]
    public int $inspectionNumber = 0;

    #[label('Locations')]
    /** @var \app\models\component\address[] $addresses */   // PHPDoc REQUIRED to type arrays of embeddables
    public array $addresses = [];
}
```
> Typed arrays of embeddables/models **must** have a `/** @var Type[] */` PHPDoc. Without it the typemap can't
> hydrate the array (do not rely on the stored `__pclass` for this).

### Static data-access API (call on any model class)
```php
inspection::countDocuments($filter=[], $options=[]);
inspection::getAll($filter=[], $sort=[], $options=[]);                 // Type[]
inspection::getPagedResponse($limit, $page, $filter=[], $options=[]);  // getResult (feed to controllerPagedDataResponse)
inspection::getOne($_id, ?$session=null);                             // throws modelDocumentNotFoundException if absent
inspection::getOneBy($filter=[], $options=[], ?$session=null);
inspection::aggregation($pipeline=[], $options=[]);                    // typemap NOT auto-applied (see below)
inspection::save($object /*by ref*/, $upsert=true, $callHooks=true, ?$session=null);      // updateDeleteResult
inspection::saveMany($objects /*by ref*/, $upsert=true, $callHooks=true, ?$session=null); // updateDeleteResult[]
inspection::delete($_id, ?$session=null);
inspection::deleteMany($items, ?$session=null);
inspection::deleteManyBy($filter=[], $options=[], ?$session=null);
```
- The typemap is applied automatically for reads **except `aggregation()`** — a pipeline may emit shapes that
  don't match the model. Documents that still carry `__pclass` get typecast during deserialization.
- `save`/`saveMany` take `$object` **by reference**; server-side changes (new `_id`, auto-increment values)
  are written back into your variable.
- `getOne` accepts a hex string or `ObjectId`.

### Transactions
Pass a shared `\MongoDB\Driver\Session` to make multiple operations atomic across collections:
```php
$session = \gcgov\framework\services\mongodb\tools\mdb::startSessionTransaction();
try {
    structure::save($structure, true, true, $session);
    inspection::save($inspection, true, true, $session);
    $session->commitTransaction();
    $session->endSession();
} catch (modelException $e) {
    if ($session->isInTransaction()) { $session->abortTransaction(); }
}
```

### Model lifecycle hooks (define on your model as needed)
```php
protected static function _beforeSave(self &$o, ?\MongoDB\Driver\Session $s=null): void {}
protected static function _afterSave(self &$o, bool $saved, ?updateDeleteResult $r=null): void {}
protected function _beforeJsonSerialize(): void {}
protected function _afterJsonDeserialize(): void {}
protected function _beforeBsonSerialize(): void {}
protected function _afterBsonUnserialize($rawBsonData): void {}
```
Hooks are opt-in (called only if the method exists). `_before/_afterSave` are skipped when `save(..., $callBeforeAfterHooks: false)`.

### Attributes (in `src/services/mongodb/attributes/`)
**Serialization / meta**
- `#[includeMeta]` (class) — include a `_meta` block in JSON output (`#[includeMeta(false)]` to disable).
- `#[label('Human Label')]` — surfaces in `_meta.fields.{field}` and labels.
- `#[excludeBsonSerialize]` / `#[excludeBsonUnserialize]` — skip a property on DB write / read.
- `#[excludeJsonSerialize]` / `#[excludeJsonDeserialize]` — skip a property on JSON out / in.
- `#[redact([roles...])]` — strip the property from JSON output when the auth user has the given role(s).
- `#[visibility(default, groups, valueIsVisibilityGroup)]` — seeds `_meta` visibility state; the UI enforces it.

**Behavior**
- `#[autoIncrement]` — auto-incrementing counter on insert. Advanced:
  `#[autoIncrement(groupByMethodName: 'getGroup', countFormatMethod: 'format')]` gives per-group sequences and
  formatted values (e.g. `FM-0001`, `2024-0001`).

**Embedding other *models* (denormalization)** — these drive `dispatcher`:
- `#[foreignKey('embeddedFieldName', $filter=[])]` on a typed model array — when the foreign model is saved,
  it's auto-inserted into parent documents where `parent._id === foreign.embeddedFieldName` (optional filter).
- `#[deleteCascade]` — deleting the parent deletes the embedded child in its own collection and everywhere
  it's embedded.
- `#[excludeFromTypemapWhenThisClassNotRoot]` — break infinite typemap recursion for mutually-nested models
  (falls back to `__pclass` typing).

### `_meta`, validation, `updateValidationState()`
Serialized objects can carry `_meta` (labels, UI/field state, validation state, DB op results). Validation uses
**Symfony Validator attributes** (`use Symfony\Component\Validator\Constraints as Assert;`) plus the framework's
`\gcgov\framework\models\customConstraints\OptionalValid`. Run it explicitly:
```php
$model->updateValidationState();               // returns a ConstraintViolationList; also updates _meta
```
Conditional validation uses **validation groups**: implement `public function _defineValidationGroups(): array`
returning group keys, and tag constraints with `groups: [...]`.

### Files: GridFS
`\gcgov\framework\services\mongodb\gridfs` stores binaries: `saveFile()`, `saveFileBase64EncodedContents()`,
`getFile()`, `deleteFile()`. Pair with `controllerFileResponse` to serve them.

### Auditing & encryption (config-driven, per database)
- **Audit**: enable per-DB in `config.json` (`audit`, `auditForward`, optional separate audit DB). Writes
  JSON-patch diffs of changes.
- **Queryable encryption**: optional `encryption` block per DB (GCP KMS). Encrypted collections must be created
  explicitly: `(new mdb($collection))->createEncryptedCollection($collection)`; rotate with `->rotateKeys()`.
  See `readme/mongodb.md` §Encryption.

---

## 8. Config

`\gcgov\framework\config` is the single static configuration API. Paths are derived by reflecting
`\app\app`'s file location, so `config::getAppDir()`, `getRootDir()`, `getModelsDir()`, `getSrvDir()`,
`getTempDir()`, `getConfigFilePath()` all work without setup. Configuration values come from the
**unified `{root}/config.json`** (hydrated once into `\gcgov\framework\models\unifiedConfig`) and are
exposed **directly on `config`** — there are no separate appConfig/environmentConfig objects (v7;
`getAppConfig()`/`getEnvironmentConfig()` remain as deprecated pass-throughs returning the unified object):
`config::getApp()` (title/guid), `getEmail()`, `getSettings()`, `getType()`, `isLocal()`,
`getRootUrl()`, `getBaseUrl()`, `getBasePath()`, `getLogging()`, `getMongoDatabases()`,
`getSqlDatabases()`, `getDefaultSqlDatabase()`, `getSqlDatabaseByName($name)`, `getMicrosoft()`,
`getJwtAuth()`, `getTokenIssuedBy()`, `getTokenPermittedFor()`, `getJwtKeyPath()`,
`getPayjunction()`, `getAppDictionary()`, `getServices()`, `getCronMonitor()`.
`serverName`, `cookieUrl` and `phpPath` were **removed in v7** — nothing read them (confirmed across
the framework and all five framework services). The PHP interpreter is `GF_PHP` / `gf cli --php`.

### Environment variables in config — `%env(...)%`
config.json supports **Symfony-style `%env(...)%` references**, resolved at load time by
`\gcgov\framework\services\environment\envVarResolver` (see `readme/environment-variables.md`).
This keeps secrets out of the committed config and lets them come from the process
environment, Docker/K8s secrets, or a `.env` file — the basis of Docker hosting.
- A file with no `%env(` substring is loaded byte-for-byte as before. You opt in by writing `%env(...)%`.
- **Every reference is REQUIRED.** There is no `default:` processor (removed in v7), and a variable
  set to the empty string counts as unset. A missing value is a startup failure naming the variable.
  A value that does not vary between environments is written as a literal, not referenced.
- Whole-value ref → typed result (`"%env(int:SMTP_PORT)%"` → `587`); embedded ref → string
  substitution. Processors, applied right-to-left: `secret, file, trim, int, bool, json`.
- **`secret`** implements the conventional `_FILE` indirection: `%env(secret:MONGO_URI)%` reads the
  file named by `MONGO_URI_FILE` if that is set, else `MONGO_URI`. A `_FILE` pointing at a missing
  file is a hard error and **never** falls back — that fallback would silently substitute a stale
  environment value for a secret that failed to mount. This is what lets one committed config.json
  serve both a developer machine (plain vars in `.env`) and production (files at `/run/secrets`).
  `secret` must be the innermost processor.
- `.env` loading (via `symfony/dotenv`, `dotEnvLoader::loadOnce()`): `{root}/.env` and/or
  `.env.local` (either may exist alone); **real environment always wins**; precedence
  `real env > .env.local > .env`. No `APP_ENV` cascade — an environment IS the variable set the
  process is given; nothing is activated or copied.
- **Reserved names**: CGI meta-variable names (`HTTP_*`, `SERVER_*`, `REQUEST_*`, `REMOTE_*`,
  `PHP_AUTH_*`, `SCRIPT_*`, `DOCUMENT_*`, `HTTPS`, `QUERY_STRING`, `CONTENT_*`, `AUTH_TYPE`,
  `GATEWAY_INTERFACE`, `PHP_SELF`, `PATH_INFO`, `PATH_TRANSLATED`) are never resolved from the
  ambient environment (request data can reach it under CGI/FastCGI) — they act as unset.
- Missing/unresolvable var → `configException` (runtime) / `cliException` (gf), naming the variable.
- `gf env --list` prints every referenced variable; `gf env --init` writes the `.env` skeleton from
  config.json itself, so the manifest cannot drift.

**`{root}/config.json`** → `\gcgov\framework\models\unifiedConfig` (one file, all sections):
```jsonc
{
  "app":      { "title": "...", "guid": "..." },
  "email":    { "fromAddress": "", "fromName": "", "useSMTP": false, "SMTPHost": "", "SMTPPort": 587, "...": "" },
  "settings": { "forceMfaForPasswordUsers": false },
  "type": "local|prod", "rootUrl": "", "basePath": "",
  "logging": { "lifecycle": false, "renderer": false,
               "destination": "stderr|file|both" },       // stderr (default) emits JSON lines
  "mongoDatabases": [ { "default": true, "database": "", "uri": "mongodb+srv://...", "logging": true,
                        "audit": false, "include_meta": true, "encryption": { /* optional */ } } ],
  "sqlDatabases":  [ { "default": true, "name": "", "dsn": "", "readAccount": {}, "writeAccount": {} } ],
  "microsoft":     { "clientId": "", "clientSecret": "", "tenant": "", "driveId": "", "fromAddress": "" },
  "jwtAuth":       { "tokenIssuedBy": "", "tokenPermittedFor": "",   // empty → derived from rootUrl / basePath
                     "redirectAfterLoginUrl": "", "redirectAfterLogoutUrl": "",
                     "keyPath": "" },                     // empty → {root}/srv/jwtCertificates
  "cronMonitor":   { "url": "" },   // empty disables cron run reporting
  "services": {                     // presence enables; absent = off; contents are that service's settings
    "auth":          { "provider": "oauth",   // "oauth" | "msFront" — required when auth is present
                       "blockNewUsers": true,
                       "defaultNewUserRoles": [],
                       "oauth": { "authorizeUrlParameters": {} } },  // only for provider "oauth"
    "userCrud":      { },
    "documentation": { }
  },
  "appDictionary": { }   // free-form key/values an application reads
}
```
`services.auth` is fail-closed: an unknown `provider`, or a block for the provider that is **not**
selected, is a startup failure. A missing block for the provider that *is* selected hydrates to its
defaults, like every other section.

---

## 9. Framework services (quick reference)

| Call | Purpose |
|------|---------|
| `services\log::{debug,info,notice,warning,error,critical,alert,emergency}($channel,$msg,$context=[])` | Monolog-backed. Destination is `logging.destination`: `stderr` (default, JSON lines) / `file` (`/logs/{channel}.log`) / `both`. |
| `services\request::getAuthUser(): authUser` | Request-scoped authenticated user (roles, id, email…). Populated by the auth service's guard. |
| `services\request::getUserClassFqdn(): string` | Resolves the app's user model FQDN: `\app\models\user`, else the Mongo `…\models\auth\user`. |
| `services\request::getPostData(): array` | Parsed request body. |
| `services\guid::create($trim=true)` | GUID string. |
| `services\http::statusText($code)` | HTTP status text. |
| `services\formatting::fileName() / xlsxTabName() / getDateIntervalHumanText()` | Sanitizers/formatters. |
| `services\jwtAuth\jwtAuth` | JWT create/validate for access & refresh tokens; JWKS. Used by the auth service — don't hand-roll auth. |
| `services\chrome\chrome::getExecutablePath() / ::getBrowserFactory()` | Headless Chrome: path to the gf-installed chrome-headless-shell binary, or a ready `\HeadlessChromium\BrowserFactory` (chrome-php/chrome). Throws `serviceException` until `gf chrome:install` has run. |
| `new services\pdodb\pdodb($readOnly=true, $databaseName='')` | Thin PDO wrapper using `sqlDatabases` config (read vs write account). |
| `services\microsoft\*` | **Deprecated** — use `andrewsauder/microsoftServices` instead. |

The authenticated-user contract is `\gcgov\framework\interfaces\auth\user`; the framework ships a default Mongo
implementation `\gcgov\framework\services\mongodb\models\auth\user` (`getFromOauth`, `verifyUsernamePassword`,
`getOneByEmail`, roles, MFA fields). Apps may override with `\app\models\user`.

---

## 10. Common recipes (for apps built on the framework)

**Add a CRUD endpoint**
1. Model: `\app\models\widget extends \gcgov\framework\services\mongodb\model` with `public ObjectId $_id;`.
2. Controller: `\app\controllers\widget implements controller`; methods return `controllerDataResponse` /
   `controllerPagedDataResponse`; catch `modelException` → throw `controllerException`.
3. Routes: add `route`s in `\app\router::getRoutes()` with method, path, class, method, auth, roles.
4. Roles: define role constants (convention `Something.Read` / `Something.Write`) and gate routes via
   `requiredRoles`.

**Read the body & save**
```php
$widget = \app\models\widget::jsonDeserialize(file_get_contents('php://input'));
\app\models\widget::save($widget);              // $widget now has its _id / generated fields
return new controllerDataResponse($widget);
```

**Paged list**
```php
$result = \app\models\widget::getPagedResponse($_GET['limit'] ?? 10, $_GET['page'] ?? 1, $filter, $options);
return new controllerPagedDataResponse($result);   // adds X-Page / X-Total-Count headers
```

**A CLI task**: register a `'CLI'` route (give it a `description:`) and run it with
`vendor/bin/gf cli /path` (legacy per-app entry: `app/cli/{env}.bat /path`); no auth.
List routes with `gf cli:list`; debug with `gf cli /path --debug`.

---

## 11. Gotchas
- Class + file names are **lowercase**. Match existing style precisely.
- Every `model` needs `public \MongoDB\BSON\ObjectId $_id;`. Every embeddable-in-an-array needs a `@var Type[]`.
- Never `exit`/`die` in a controller (breaks the lifecycle + `_after` hooks); return a response.
- `aggregation()` does **not** auto-apply the typemap.
- Deeply nested/mutually-referential models can infinite-loop the typemap → use
  `#[excludeFromTypemapWhenThisClassNotRoot]`.
- There's no auth without `services.auth`; it registers a **global guard** over every
  `authentication:true` route. The framework refuses to boot if authenticated routes exist without it
  (and without `\app\router::providesAuthentication()`), rather than serving them unprotected.
- Set `logging.lifecycle=true` in `config.json` to trace the entire pipeline when debugging routing/auth.
- Every `%env()` reference is required — there is no default and `FOO=` counts as unset. `gf env` says
  which one is missing.
- Logs go to **stderr** by default, not `logs/*.log`. An app on IIS sets `logging.destination: "file"`.
- JWT signing keys are gitignored, so they are never in a built image: a container must point
  `jwtAuth.keyPath` at a provisioned directory or authentication cannot work.

---

## 12. Framework Services

Framework Services ship **inside** the framework (`src/services/`). Enable one by adding its block to the
`services` section of `config.json` — presence enables, and the block's contents are its settings, so
activation and configuration are one statement. See ADR 0005.

| Config key | Namespace | Adds |
|------------|-----------|------|
| `services.auth` (`provider: "oauth"`) | `\gcgov\framework\services\auth` | Full OAuth server (password + third-party + MFA), JWKS, file tokens, global JWT guard. |
| `services.auth` (`provider: "msFront"`) | same | Exchange a Microsoft token the front end holds for an app JWT, plus the same JWKS/file tokens/guard. |
| `services.userCrud` | `\gcgov\framework\services\userCrud` | `/user` CRUD over the resolved user model (`User.Read` / `User.Write`). |
| `services.documentation` | `\gcgov\framework\services\documentation` | `GET /documentation.yaml` (OpenAPI from annotations). |

There is **one** auth service with two providers, so two cannot be active at once — it is unrepresentable
rather than merely discouraged.

`\gcgov\framework\services\cronMonitor\cronMonitor` is **not** a Framework Service: it registers no
routes and takes no part in the lifecycle. Construct it directly and configure `cronMonitor.url`.

The separately published `gcgov/framework-service-*` packages still exist for **v6** applications. The
framework declares a `conflict` against all five, so a v7 application cannot install both.

---

## 13. Adding a new Framework Service
Services live in this repository; there is no out-of-tree extension point (ADR 0005). An application
needing routes of its own puts them in `\app\router`, which already runs first in the guard chain.

- Code in `src/services/<name>/`, namespace `\gcgov\framework\services\<name>`.
- `src/services/<name>/router.php` implements `\gcgov\framework\interfaces\router` — just `getRoutes()`
  and `authentication()`, no lifecycle hooks. Prefix routes with `config::getBasePath()`.
- Controllers under `\gcgov\framework\services\<name>\controllers\…` implementing `controller`.
- Config: add a nullable property to `\gcgov\framework\models\config\services` and a DTO beside it in
  `src/models/config/services/`. Nullable means absent = disabled. Give the router its typed config as a
  constructor argument — no singletons.
- Construct it in `framework\router::__construct()` behind `if( $services-><name> !== null )`.
- Return `false` from `authentication()` only to deny; `true` to allow.
- Mirror the tests under `tests/Unit/Services/<Name>/`. `composer ci` before pushing.

---

## 14. Build / test / CI
- Install: `composer install`. PHP `>=8.4`; ext `mongodb`, `sodium`, `fileinfo`, `pdo`.
- Static analysis: `composer phpstan` (PHPStan; `phpstan-stubs/` provides stubs for optional deps).
- Tests: `composer test` (PHPUnit; `tests/` mirrors `src/`, uses `tests/Shims/MongoDBShims.php` so unit tests
  run without a live Mongo). `composer ci` = phpstan + test.
- GitHub Actions (`.github/workflows/ci.yml`) runs on PHP 8.4. **Run `composer ci` before pushing.**
- Every application gets `GET {basePath}/health` (liveness, no I/O) and `/health/ready` (readiness,
  pings Mongo, 503 when a dependency is down) from `services/health/` — contributed by the framework
  router itself, not opt-in, because a deploy pipeline cannot gate on an endpoint an app might omit.
- When you change `src/`, add/adjust the mirrored test under `tests/Unit/…`.

---

## 15. Where to look
- Full narrative + app file system: `README.md`.
- Core file examples: `readme/{index.php,cli-index.php,app.php,router.php,renderer.php}.md`.
- **Mongo (authoritative, deep)**: `readme/mongodb.md`.
- **gf CLI (authoritative)**: `readme/gf.md`.
- A real, minimal consuming controller: `src/services/userCrud/controllers/user.php`.

---

## 16. The gf CLI (`bin/gf`, `src/cli/`)

The framework ships a symfony/console-based command line tool exposed as a composer bin: every
consuming app gets `vendor/bin/gf` (+ `gf.bat` on Windows). Full reference: `readme/gf.md`.

- **Commands** (canonical names; `gf db run` auto-resolves to `db:run`): `cli`, `cli:list`,
  `cert:generate-auth`, `chrome:install`, `chrome:update`, `chrome:status`, `db:run`, `env`, `init`,
  `migrate`, `completion`, `completion:powershell`. Bare `gf` lists everything.
  **Removed in v7**: `deploy` (a Release is an immutable image pinned by digest — see ADR 0002),
  `db:restore` (it required production credentials on every workstation), and `setup` (replaced by
  the non-interactive `init`, since bootstrap belongs in a scaffolding script or a devcontainer).
- **`gf env`** validates that config.json resolves; `--list` prints every referenced variable and
  whether it is currently set; `--init` writes the `.env` skeleton (refuses to overwrite without
  `--force`). **`gf init --title="…"`** bootstraps a scaffolded app: title, guid, `.env`, JWT keys,
  chrome. **`gf migrate`** converts a v6 app — its `plan()` is a pure function of `app.json` +
  `environment.json`, so it is unit-tested rather than run hopefully.
- **chrome-headless-shell**: `chrome:install`/`chrome:update` download the Chrome for Testing
  Stable build for the current platform into `srv/chrome/{version}/` (manifest:
  `srv/chrome/installation.json`; needs ext-zip; `gf setup` auto-installs, `--skip-chrome` opts
  out; update prunes old versions). Apps consume it via `services\chrome\chrome` (§9); shared
  logic lives in `services/chrome/chromeInstallation.php`, download orchestration in
  `src/cli/chromeInstaller.php` (injectable Guzzle client — tests are network-free).
- **Architecture** (`src/cli/`): `application` (command registration + provider discovery),
  `appContext` (app-root locator: composer autoload path first, then cwd walk-up; config via
  `loadConfig()` and `configReferences()`, both delegating to `services\environment\configLoader`;
  never boots the request lifecycle),
  `routeCatalog` (CLI-route enumeration via `router::getMergedRoutes()`), `phpProcess`,
  `mongoTools`, `cliException` (user-facing errors),
  `internal/run-route.php` (child-process route runner; maps response status ≥400 → exit 1).

- **Command tiers**: no context (list/help/completion — must work anywhere, including this repo);
  root-only (env, db:run, cert:*, init, migrate — config JSON only, no `\app` boot);
  app-boot (cli, cli:list — `assertAppLoadable()`; `\app\app::_before()` is deliberately NOT called).
- **`gf cli <route>`** always spawns a fresh PHP child process (Xdebug flags need fresh INI;
  isolates `exit()`; interpreter picked via `--php` > `GF_PHP` > current).
  The interpreter must be the CLI binary — `php-cgi`/`php-fpm`/`php-win` are swapped for the
  `php`/`php.exe` beside them, else rejected; the child always gets `-dregister_argc_argv=1`, and
  `internal/run-route.php` assumes neither `$argv` nor `STDERR` exists until it has checked.
- **Expandability**: an app implements `\app\cli\commandProvider`
  (`\gcgov\framework\cli\commandProvider::getCommands()`); discovery is fail-safe — errors never
  break gf (visible with `-v`). Framework Services register commands directly in
  `application::__construct()`, since they are part of the framework.
- When adding a command: lowercase lowerCamelCase class in `src/cli/commands/`, `#[AsCommand]`
  attribute, register it in `application::__construct()`, throw `cliException` for user errors,
  add a mirrored test in `tests/Unit/Cli/`. Keep the logic in a pure static method the test can
  call directly (e.g. `migrateCommand::plan()`, `envCommand::renderEnvFile()`) rather than driving
  everything through CommandTester.

---

## Agent skills

### Issue tracker

Issues live in GitHub Issues for `gcgov/framework`, driven by the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one root `CONTEXT.md` plus `docs/adr/`. See `docs/agents/domain.md`.

`CONTEXT.md` is the glossary — read it before naming anything. Note especially that **Environment**
(a deployment target, defined by the variable set a process is given) and **Zone** (a network
isolation boundary) are different things, and that v6's "environment variant" no longer exists.

ADRs recorded so far: 0001 fail-closed configuration · 0002 immutable Release pinned by digest ·
0003 secrets never decrypt in CI or on hosts · 0004 one self-hosted runner per Zone ·
0005 Framework Services are built in and config-activated.
