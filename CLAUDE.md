# CLAUDE.md — gcgov/framework

Guidance for Claude when working **on this framework** or **on any application/plugin built on it**.
This file is the fast path to a correct mental model. For exhaustive reference, see `README.md` and the
`readme/` directory (especially `readme/mongodb.md`).

---

## 1. What this is

`gcgov/framework` is a small, opinionated PHP 8.3+ framework for building **REST APIs** (and optionally
SSR apps) for Garrett County Government. Composer package name: `gcgov/framework`, PSR-4 root
`gcgov\framework\` → `src/`.

A full API with Microsoft OAuth authentication, user CRUD, and OpenAPI docs can be assembled with **almost
no custom code** by installing framework-service plugins (see §12). The framework's standout feature is its
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
├── config.php             # static config + path resolver (app dir, srv dir, app.json, environment.json)
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
  `\gcgov\framework\...`. Plugins are `\gcgov\framework\services\<plugin>\...`.
- Do not "modernize" to StudlyCase class names — you will break PSR-4 autoloading and every reference.

---

## 3. The application contract (what a consuming app must provide)

An app that runs a full request lifecycle must supply, in its `/app` directory:

| File | Class | Must implement |
|------|-------|----------------|
| `/app/app.php` | `\app\app` | `\gcgov\framework\interfaces\app` |
| `/app/router.php` | `\app\router` | `\gcgov\framework\interfaces\router` |
| `/app/renderer.php` | `\app\renderer` | `\gcgov\framework\interfaces\render` |
| `/app/controllers/*.php` | e.g. `\app\controllers\widget` | `\gcgov\framework\interfaces\controller` |

Required config files (missing either throws `configException` at request time):
- `/app/config/app.json`
- `/app/config/environment.json`

Typical app tree (scaffolding template adds more — `srv/`, `db/`, `scripts/`, `www/web.config`, etc.):
```
/api
├── app/{app,router,renderer,constants}.php
│   ├── cli/index.php            # CLI entry
│   ├── config/{app,environment}.json
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
 new app()  →  app->registerFrameworkServiceNamespaces()   # returns plugin namespaces to load
 router::_before()
 new framework\router(serviceNamespaces)  # instantiates each plugin's \{ns}\router if present, then \app\router
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
  lifecycle. (The documentation plugin's `yaml()` is the one deliberate exception.)
- Route method parameters are bound positionally from the URL pattern placeholders.

---

## 5. Routing

`\app\router::getRoutes()` returns `\gcgov\framework\models\route[]`. Plugin routers contribute routes too;
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
`config::getEnvironmentConfig()->getBasePath()`, which is what plugin routers use).

### Authentication guard flow (`framework\router::route()`)
For a matched route with `authentication === true`:
1. `\app\router::authentication($routeHandler)` runs **first** (your custom checks). Return `false` → 401.
2. Then **each plugin router's** `authentication()` runs — unless `\app\router` defines
   `getRunFrameworkServiceRouteAuthentication($routeHandler): bool` and returns `false` for that route.
3. Auth plugins (oauth-server / auth-ms-front) validate the JWT from the `Authorization: Bearer …` header
   (or `?fileAccessToken=` when `allowShortLivedUrlTokens`), populate the request-scoped `authUser`, and
   enforce `requiredRoles` (missing header → 401, missing role → 403).

Routes with `authentication === false` skip all of this. There is **no built-in auth**; it comes from a
plugin (§12). A `routeException` thrown anywhere in this flow becomes the HTTP error response.

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
- **Audit**: enable per-DB in `environment.json` (`audit`, `auditForward`, optional separate audit DB). Writes
  JSON-patch diffs of changes.
- **Queryable encryption**: optional `encryption` block per DB (GCP KMS). Encrypted collections must be created
  explicitly: `(new mdb($collection))->createEncryptedCollection($collection)`; rotate with `->rotateKeys()`.
  See `readme/mongodb.md` §Encryption.

---

## 8. Config

`\gcgov\framework\config` is a static accessor. Paths are derived by reflecting `\app\app`'s file location, so
`config::getAppDir()`, `getRootDir()`, `getConfigDir()`, `getModelsDir()`, `getSrvDir()`, `getTempDir()` all
work without setup. Config DTOs are `jsonDeserialize`-hydrated from the two JSON files.

**`app.json`** → `\gcgov\framework\models\appConfig`:
```jsonc
{
  "app":      { "title": "...", "guid": "..." },
  "email":    { "fromAddress": "", "fromName": "", "useSMTP": false, "SMTPHost": "", "SMTPPort": 587, "...": "" },
  "settings": { "useSession": false, "forceMfaForPasswordUsers": false }
}
```
**`environment.json`** → `\gcgov\framework\models\environmentConfig` (accessor helpers: `getRootUrl()`,
`getBaseUrl()`, `getBasePath()`, `isLocal()`, `getDefaultSqlDatabase()`, `getSqlDatabaseByName()`):
```jsonc
{
  "type": "local|prod", "serverName": "", "rootUrl": "", "basePath": "", "baseUrl": "", "cookieUrl": "",
  "logging": { "lifecycle": false, "renderer": false },   // lifecycle=true logs the whole request pipeline
  "mongoDatabases": [ { "default": true, "database": "", "uri": "mongodb+srv://...", "logging": true,
                        "audit": false, "include_meta": true, "encryption": { /* optional */ } } ],
  "sqlDatabases":  [ { "default": true, "name": "", "dsn": "", "readAccount": {}, "writeAccount": {} } ],
  "microsoft":     { "clientId": "", "clientSecret": "", "tenant": "", "driveId": "", "fromAddress": "" },
  "jwtAuth":       { "tokenIssuedBy": "", "tokenPermittedFor": "", "redirectAfterLoginUrl": "", "redirectAfterLogoutUrl": "" },
  "appDictionary": { }   // free-form key/values plugins read (e.g. cronMonitorUrl)
}
```

---

## 9. Framework services (quick reference)

| Call | Purpose |
|------|---------|
| `services\log::{debug,info,notice,warning,error,critical,alert,emergency}($channel,$msg,$context=[])` | Monolog-backed; writes `/logs/{channel}.log`. |
| `services\request::getAuthUser(): authUser` | Request-scoped authenticated user (roles, id, email…). Populated by the auth plugin's guard. |
| `services\request::getUserClassFqdn(): string` | Resolves the app's user model FQDN: `\app\models\user`, else the Mongo `…\models\auth\user`. |
| `services\request::getPostData(): array` | Parsed request body. |
| `services\guid::create($trim=true)` | GUID string. |
| `services\http::statusText($code)` | HTTP status text. |
| `services\formatting::fileName() / xlsxTabName() / getDateIntervalHumanText()` | Sanitizers/formatters. |
| `services\jwtAuth\jwtAuth` | JWT create/validate for access & refresh tokens; JWKS. Used by auth plugins — don't hand-roll auth. |
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
- There's no auth without an auth plugin; and auth plugins register a **global guard** over every
  `authentication:true` route in the app.
- Set `logging.lifecycle=true` in `environment.json` to trace the entire pipeline when debugging routing/auth.

---

## 12. Extensions / plugins

Register a plugin by adding its namespace to `\app\app::registerFrameworkServiceNamespaces()`; the framework
then auto-discovers `\{namespace}\router` and merges its routes + auth guard.

| Plugin (repo) | Namespace to register | Adds |
|---------------|-----------------------|------|
| `gcgov/framework-service-documentation` | `\gcgov\framework\services\documentation` | `GET /documentation.yaml` (OpenAPI from annotations). |
| `gcgov/framework-service-auth-ms-front` | `\gcgov\framework\services\authmsfront` | Exchange a Microsoft token for an app JWT; global JWT guard. |
| `gcgov/framework-service-auth-oauth-server` | `\gcgov\framework\services\authoauth` | Full OAuth server (password + third-party + MFA); global JWT guard. |
| `gcgov/framework-service-user-crud` | `\gcgov\framework\services\usercrud` | `/user` CRUD over the resolved user model. |
| `gcgov/framework-service-gcgov-cron-monitor` | `\gcgov\framework\services\cronMonitor` | Report cron start/end to a monitor service. |

Each plugin repo has its own `CLAUDE.md` with specifics. Only **one** authentication plugin should be active
at a time (oauth-server OR auth-ms-front).

---

## 13. Authoring a new plugin (framework-service)
- `composer.json`: `"type": "framework-service"`, PSR-4 `gcgov\framework\services\<name>\ → src/`.
- Provide `src/router.php` = `\gcgov\framework\services\<name>\router implements \gcgov\framework\interfaces\router`
  with `getRoutes()`, `authentication()`, static `_before()/_after()`. Prefix routes with
  `config::getEnvironmentConfig()->getBasePath()`.
- Controllers live under `\gcgov\framework\services\<name>\controllers\…` and implement `controller`.
- Config via a singleton (`getInstance()`) the app tweaks in `app::_before()` (see oauth-server's `oauthConfig`),
  and/or `environment.json.appDictionary`.
- Return `false` from a plugin `authentication()` only to deny; return `true` to allow.
- Optional: contribute gf commands with `src/cli/commandProvider.php` =
  `\gcgov\framework\services\<name>\cli\commandProvider implements \gcgov\framework\cli\commandProvider`
  returning symfony/console command instances (namespace the command names, e.g. `docs:regenerate`). See §16.

---

## 14. Build / test / CI
- Install: `composer install`. PHP `>=8.3`; ext `mongodb`, `sodium`, `fileinfo`, `pdo`.
- Static analysis: `composer phpstan` (PHPStan; `phpstan-stubs/` provides stubs for optional deps).
- Tests: `composer test` (PHPUnit; `tests/` mirrors `src/`, uses `tests/Shims/MongoDBShims.php` so unit tests
  run without a live Mongo). `composer ci` = phpstan + test.
- GitHub Actions (`.github/workflows/ci.yml`) runs both on PHP 8.3 and 8.4. **Run `composer ci` before pushing.**
- When you change `src/`, add/adjust the mirrored test under `tests/Unit/…`.

---

## 15. Where to look
- Full narrative + app file system: `README.md`.
- Core file examples: `readme/{index.php,cli-index.php,app.php,router.php,renderer.php}.md`.
- **Mongo (authoritative, deep)**: `readme/mongodb.md`.
- **gf CLI (authoritative)**: `readme/gf.md`.
- A real, minimal consuming controller: the user-crud plugin's `src/controllers/user.php`.

---

## 16. The gf CLI (`bin/gf`, `src/cli/`)

The framework ships a symfony/console-based command line tool exposed as a composer bin: every
consuming app gets `vendor/bin/gf` (+ `gf.bat` on Windows). Full reference: `readme/gf.md`.

- **Commands** (canonical names; `gf db restore` auto-resolves to `db:restore`): `cli`, `cli:list`,
  `cert:generate-auth`, `db:restore`, `db:run`, `env`, `setup`, `deploy`, `completion`,
  `completion:powershell`. Bare `gf` lists everything.
- **Architecture** (`src/cli/`): `application` (command registration + provider discovery),
  `appContext` (app-root locator: composer autoload path first, then cwd walk-up; lazy config
  access via `loadEnvironmentConfig($variant)` — never boots the request lifecycle),
  `routeCatalog` (CLI-route enumeration via `router::getMergedRoutes()`), `phpProcess`,
  `environmentFiles`, `tokenReplacer`, `mongoTools`, `cliException` (user-facing errors),
  `internal/run-route.php` (child-process route runner; maps response status ≥400 → exit 1).
- **Command tiers**: no context (list/help/completion — must work anywhere, including this repo);
  root-only (env, db:*, cert:*, deploy, setup — config JSON only, no `\app` boot);
  app-boot (cli, cli:list — `assertAppLoadable()`; `\app\app::_before()` is deliberately NOT called).
- **`gf cli <route>`** always spawns a fresh PHP child process (Xdebug flags need fresh INI;
  isolates `exit()`; interpreter picked via `--php` > `GF_PHP` > environment.json `phpPath` > current).
- **Expandability**: apps (`\app\cli\commandProvider`) and plugins
  (`{ns}\cli\commandProvider`) implement `\gcgov\framework\cli\commandProvider::getCommands()`.
  Discovery is fail-safe — errors never break gf (visible with `-v`).
- When adding a command: lowercase lowerCamelCase class in `src/cli/commands/`, `#[AsCommand]`
  attribute, register it in `application::__construct()`, throw `cliException` for user errors,
  add a mirrored test in `tests/Unit/Cli/` (external binaries are exercised via pure
  arg-builder methods, e.g. `dbRestoreCommand::buildDumpCommand()`).
- The legacy `scripts/*.ps1` are deprecated wrappers kept for backward compatibility.
