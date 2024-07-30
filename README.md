# gcgov/framework

A PHP framework for Garrett County Government, Maryland, USA applications.

The framework can be used to generate a full SSR app or as a rest API. It is primarily used internally to generate APIs.
Using the available extensions, a full-fledged API with Microsoft Oauth authentication can be created with no custom
code.

## Getting Started

The easiest way to start is to use the [framework scaffolding project](https://github.com/gcgov/framework-app-template)
to start a new api and the [frontend app template](https://github.com/gcgov/framework-frontend-template) to start a
corresponding front end application.

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

The webserver should point requests to /www/index.php. URL rewriting the original path to url parameter `R0` is
required.

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


## CLI
Using the framework scaffolding project, you can run the app from CLI with `> app/cli/{env}.bat {url-path}` Ex: `> app/cli/local.bat /structure/cleanup`

To enable XDebug on the CLI execution, run `> app/cli/local-debug.bat {url-path}`
Ex: `> app/cli/local-debug.bat /structure/cleanup`

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


### Microsoft Services
Deprecated - use https://github.com/andrewsauder/microsoftServices instead

### MongoDB

### PDODB

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
