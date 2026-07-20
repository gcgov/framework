# gcgov/framework

A PHP framework for Garrett County Government, Maryland, USA applications.

The framework can be used to generate a full SSR app or as a rest API. It is primarily used internally to generate APIs.
Using the available extensions, a full-fledged API with Microsoft Oauth authentication can be created with no custom
code.

## Getting Started

The easiest way to start is to use the [framework scaffolding project](https://github.com/gcgov/framework-app-template)
to start a new api and the [frontend app template](https://github.com/gcgov/framework-frontend-template) to start a
corresponding front end application.

### Requirements

Framework package requirements from `composer.json`:

* PHP `>=8.3`
* PHP extensions: `ext-mongodb`, `ext-fileinfo`, `ext-pdo`

Install dependencies with Composer:

```bash
composer install
```

### Minimum Application Contract

The framework expects these app classes/files to exist in your `/app` directory:

* `\app\app` implementing `\gcgov\framework\interfaces\app`
* `\app\router` implementing `\gcgov\framework\interfaces\router`
* `\app\renderer` implementing `\gcgov\framework\interfaces\render`

Controllers should implement `\gcgov\framework\interfaces\controller`.

Required configuration files:

* `/app/config/app.json`
* `/app/config/environment.json`

If either file is missing, the framework throws a config exception during request handling.

## System Architecture

### Application File System

All apps utilizing the framework for an entire lifecycle should use this file structure.

```
/api
├── app
│   ├── app.php
│   ├── constants.php
│   ├── renderer.php
│   ├── router.php
│   ├── cli
│   │   ├── index.php
│   │   ├── local.bat
│   │   ├── local-debug.bat
│   │   └── prod.bat
│   ├── config
│   │   ├── app.json
│   │   └── environment.json
│   ├── controllers
│   │   └── {controller.php}
│   └── models
│       └── {model.php}
└── www
    └── index.php
```

When you start with the [framework scaffolding project](https://github.com/gcgov/framework-app-template), you'll
automatically start with some extra folders and tools.

```
/api
│...
├── www
│   │...
│   ├── web.config
│   ├── web-local.config
│   └── web-prod.config
├── app
│   │...
│   └── config
│       └── environment-local.json
│       └── environment-prod.json
├── scripts
│   ├── create-jwt-keys.ps1
│   └── setup.ps1
├── srv
│   ├── {env}
│   │   └── php.ini
│   ├── tmp
│   │   ├── files
│   │   ├── opcache
│   │   ├── sessions
│   │   ├── soaptmp
│   │   └── tmp
│   └── jwtCertificates
├── db
│   ├── backup
│   ├── restore-live-to-local.ps1
│   └── local-createuser.js
├── logs
└── update-production.ps1
```

### Core Files and Application Namespacing

The webserver should point requests to `/www/index.php`. URL rewriting should route application paths to this file.

`gcgov\framework\router` routes using `$_SERVER['REQUEST_URI']` and `$_SERVER['REQUEST_METHOD']`. Rewrite rules should preserve the original request path in `REQUEST_URI`.

* [index.php](readme/index.php.md)

CLI requests should point to /app/cli/index.php.
* [app/cli/index.php](readme/cli-index.php.md)

The framework will register namespace `\app` to the `/app` directory and requires three core files in the root
of `/app`:
* [app.php](readme/app.php.md)
* [renderer.php](readme/renderer.php.md)
* [router.php](readme/router.php.md)

### Components

#### Controllers

`\app\controllers`

A controller method called by the router must return one of the following supported types. It should always provide a
response and never end code execution manually to ensure that the entire application lifecycle is executed. 

New controller response types may be added to the framework to support new scenarios by adding the type and setting up 
rendering methods in `\gcgov\framework\renderer`

* \gcgov\framework\models\controllerDataResponse
* \gcgov\framework\models\controllerPagedDataResponse
* \gcgov\framework\models\controllerFileResponse
* \gcgov\framework\models\controllerFileBase64EncodedContentResponse
* \gcgov\framework\models\controllerViewResponse

#### Models
`\app\models`

#### Interfaces

`\app\interfaces`

#### Exceptions

`\app\exceptions`

#### Traits

`\app\traits`

#### Services

`\app\services`

### Routing

`\app\router` method `getRoutes()` must return an array of `\gcgov\framework\models\route` that maps the URL path to the
controller and defines authentication requirements.

```php
\gcgov\framework\models\route(
    string|array $httpMethod = '', 
    string $route = '', 
    string $class = '', 
    string $method = '', 
    bool $authentication = false, 
    array $requiredRoles = [], 
    bool $allowShortLivedUrlTokens=false
)
```

The following route will map incoming `GET` requests to `/structure` to controller `\app\controllers\structure`
method `getAll`. The route requires authentication and the user must have the role `Structure.Read` to execute the
request.

```php
new route( 'GET', 'structure', '\app\controllers\structure', 'getAll', true, [ 'Structure.Read' ] );
```

When loading the app via CLI, the method will be `CLI` instead of a normal HTTP method. CLI routes do not support authentication.
```php
new route( 'CLI', 'structure/cleanup', '\app\controllers\structure', 'cleanup', false );
```

## Request Lifecycle

1. `\www\index.php`
1. `\app\app::_before()`
1. `\app\app::__construct()`
1. `\app\router::_before()`
1. `\app\router::__construct()`
1. `\app\router::route()`
1. `\app\router::_after()`
1. `\app\renderer::_before()`
1. `\app\controllers\{route-controller}::_before()`
1. `\app\controllers\{route-controller}::__construct()`
1. `\app\controllers\{route-controller}::{route-method}()`
1. `\app\controllers\{route-controller}::_after()`
1. `\app\renderer::_after()`
1. `\app\app::_after()`


## Command line: gf
The framework ships a multiplatform command line tool at `vendor/bin/gf` (Windows: `vendor\bin\gf.bat`).
Run `gf` with no arguments to list every available command. Highlights:

```
gf cli /structure/cleanup        # run a CLI route (replaces app/cli/{env}.bat)
gf cli /structure/cleanup --debug# run with Xdebug (replaces local-debug.bat)
gf cli:list                      # list the app's CLI routes
gf cert:generate-auth            # JWT signing keys (replaces create-jwt-keys.ps1)
gf db:restore --from=prod        # pull a source env's mongo dbs into the local env
gf db:run db/script.js           # run a mongosh script with config-managed credentials
gf env local                     # activate environment config file variants
gf setup                         # bootstrap a scaffolded app (replaces setup.ps1)
gf deploy                        # tag-based deployment (replaces update-production.ps1)
```

Tab completion is available for bash/zsh/fish (`gf completion --help`) and PowerShell
(`gf completion:powershell`), including dynamic completion of the app's CLI route names.
Apps and plugins can add their own gf commands via a `cli\commandProvider` class.

**See [readme/gf.md](readme/gf.md) for the full reference and the migration guide.**

The legacy per-app entry (`> app/cli/{env}.bat {url-path}`, `local-debug.bat` for XDebug) keeps
working, but new apps should use gf. The `scripts/*.ps1` files shipped with the framework are
deprecated in favor of `gf setup` and `gf cert:generate-auth`.

## Framework Services

### Formatting
1. Sanitize file name: `\gcgov\framework\services\formatting::fileName( string $fileName, string $replacementForIllegalChars = '-', bool $forceLowerCase = true ): string`
1. Sanitize Excel tab name: `\gcgov\framework\services\formatting::xlsxTabName( string $tabName, string $replacementForIllegalChars = ' ', bool $forceLowerCase = false ) : string`
1. Format DateInterval to human readable string: `\gcgov\framework\services\formatting::getDateIntervalHumanText( \DateInterval $interval ) : string`

### GUID
Create a GUID `\gcgov\framework\services\guid::create()`

### HTTP
Get status text for HTTP code `\gcgov\framework\services\http::statusText( int $code )`

### Logging
`\gcgov\framework\services\log` will automatically create and append a log in /logs with a filename equal to the channel

* Debug `\gcgov\framework\services\log::debug( string $channel, string $message, array $context = [] )`
* Info `\gcgov\framework\services\log::info( string $channel, string $message, array $context = [] )`
* Notice `\gcgov\framework\services\log::notice( string $channel, string $message, array $context = [] )`
* Warning `\gcgov\framework\services\log::warning( string $channel, string $message, array $context = [] )`
* Error `\gcgov\framework\services\log::error( string $channel, string $message, array $context = [] )`
* Critical `\gcgov\framework\services\log::critical( string $channel, string $message, array $context = [] )`
* Alert `\gcgov\framework\services\log::alert( string $channel, string $message, array $context = [] )`
* Emergency `\gcgov\framework\services\log::emergency( string $channel, string $message, array $context = [] )`

### JWT Auth & Certificates
`\gcgov\framework\services\jwtAuth\jwtAuth()` provides all JWT authentication mechanisms. Explore the **Oauth Server 
Service** and **Microsoft Auth Token Exchange** extensions before rolling a new solution for authentication.

### Microsoft Services
Deprecated - use https://github.com/andrewsauder/microsoftServices instead

### MongoDB
Comprehensive database modeling system `\gcgov\framework\services\mongodb`.

Use this service by defining classes in `\app\models` that extend `\gcgov\framework\services\mongodb\model` (top-level collection documents) or `\gcgov\framework\services\mongodb\embeddable` (nested documents that are not stored as their own collection).

#### How Models Work

The Mongo model stack is layered like this:

1. `embeddable` handles BSON and JSON serialization/deserialization, typemaps, `_meta`, and validation helpers.
2. `dispatcher` handles embedded-model propagation (insert/update/delete in parent collections) and cascade deletion behavior.
3. `factory` provides static data access and persistence APIs.
4. `model` is the base class your top-level collection models extend.

Every class extending `\gcgov\framework\services\mongodb\model` must define a public `$_id` field of type `\MongoDB\BSON\ObjectId`.

By default, a model's collection name is the class name. You can override this and user-facing names with constants:

```php
final class inspection extends \gcgov\framework\services\mongodb\model {
    const _COLLECTION = 'inspection';
    const _HUMAN = 'inspection';
    const _HUMAN_PLURAL = 'inspections';

    public \MongoDB\BSON\ObjectId $_id;
}
```

#### Core Model APIs (Static)

Available through any model class (for example `\app\models\inspection`):

* Read: `countDocuments`, `getAll`, `getPagedResponse`, `getOne`, `getOneBy`
* Write: `save`, `saveMany`
* Delete: `delete`, `deleteMany`, `deleteManyBy`
* Analytics: `aggregation`

Save and delete operations are transaction-aware. If you do not pass a `\MongoDB\Driver\Session`, the service opens and manages one for the operation.

#### Serialization, Typemaps, and `_meta`

`embeddable` provides the serialization pipeline used by all models and embedded documents:

* BSON typemaps are generated from typed properties so Mongo results hydrate into your model classes.
* Date handling maps Mongo `UTCDateTime` values to PHP `DateTimeImmutable` during read.
* `_meta` is managed automatically and can include field labels, UI state, validation state, and DB operation results.
* Property and class attributes control behavior such as `#[includeMeta]`, `#[excludeBsonSerialize]`, `#[excludeBsonUnserialize]`, `#[excludeJsonDeserialize]`, and `#[redact(...)]`.

#### Embedded Model Dispatch and Cascading

When saving a model, `dispatcher` can automatically propagate changes to embedded copies in other collections:

* `_insertEmbedded` pushes newly saved models into configured parent arrays.
* `_updateEmbedded` updates embedded copies by matching `_id`.
* `_deleteEmbedded` removes embedded copies when the source model is deleted.
* `_deleteCascade` recursively deletes related models marked with cascade attributes.

This behavior is driven by typemap + attribute metadata, enabling denormalized Mongo document patterns while keeping embedded data synchronized.

#### Additional Features Provided by the Mongo Service

* `#[autoIncrement]` support for generated counters (including grouped/formatting scenarios).
* `_beforeSave` and `_afterSave` lifecycle hooks on models.
* Optional auditing/diff logging when enabled in environment config.
* Validation integration via `updateValidationState()` using Symfony validation attributes.

For full reference, configuration options, attributes, and detailed examples, see:

* [MongoDB Service](readme/mongodb.md)


### PDODB
Initiate PDO connections using SQL connection details in app/config/environment.json. It is only a small wrapper around 
the native PDO class.

Read user connection: `new gcgov\framework\services\pdodb\pdodb(true, $databaseName)`

Write user connection: `new gcgov\framework\services\pdodb\pdodb(false, $databaseName)`


## Extensions
Extensions add service or app level functionality to the app that registers them. Extensions may expose new endpoints.

* **Open API Documentation** `gcgov/framework-service-documentation`
    * https://github.com/gcgov/framework-service-documentation
    * Add namespace `\gcgov\framework\services\documentation` to `\app\app->registerFrameworkServiceNamespaces()`
* **Microsoft Auth Token Exchange** `gcgov/framework-service-auth-ms`
    * https://github.com/gcgov/framework-service-auth-ms-front
    * Add namespace `\gcgov\framework\services\authmsfront` to `\app\app->registerFrameworkServiceNamespaces()`
* **Oauth Server Service** `gcgov/framework-service-auth-oauth-server`
    * https://github.com/gcgov/framework-service-auth-oauth-server
    * Add namespace `\gcgov\framework\services\authoauth` to `\app\app->registerFrameworkServiceNamespaces()`
* **User CRUD** `gcgov/framework-service-user-crud`
    * https://github.com/gcgov/framework-service-user-crud
    * Add namespace `\gcgov\framework\services\usercrud` to `\app\app->registerFrameworkServiceNamespaces()`
* **Cron Monitor** `gcgov/framework-service-gcgov-cron-monitor`
    * https://github.com/gcgov/framework-service-gcgov-cron-monitor/
    * Add namespace `gcgov\framework\services\cronMonitor` to `\app\app->registerFrameworkServiceNamespaces()`
