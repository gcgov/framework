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

When you start with the [framework scaffolding project](https://github.com/gcgov/framework-app-template), you'll automatically start with some extra folders and tools.
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
### Application Namespacing
The framework will register namespace `\app`, mapped to the `/app` directory. 

#### Controllers
`\app\controllers`

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

## Routing

## Request Lifecycle

## Responses

## CLI

## Services
### Formatting
### GUID
### HTTP
### Logging
### Request
### JWT Certificates
### Microsoft Services
### MongoDB
### PDODB

## Extensions

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
