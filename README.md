# gcgov/framework
A PHP framework for Garrett County Government, Maryland, USA and everyone else who writes like us.

## Usage
The easiest way to start is to use the [scaffolding project](https://github.com/gcgov/framework-app-template) to spin up a new app.

## Documentation

### Services (Plugins/Extensions/Modules)
#### Installation
1. Require using composer
2. Add the namespace of the package to `\app\app->registerFrameworkServiceNamespaces()`


#### Available Services
* **Documentation Service** `gcgov/framework-service-documentation`
    * Require using Composer https://packagist.org/packages/gcgov/framework-service-documentation
    * Add namespace `\gcgov\framework\services\documentation` to `\app\app->registerFrameworkServiceNamespaces()`
* **Microsoft Auth Token Exchange** `gcgov/framework-service-auth-ms`
    * Require using Composer https://packagist.org/packages/gcgov/framework-service-auth-ms-front
    * Add namespace `\gcgov\framework\services\authmsfront` to `\app\app->registerFrameworkServiceNamespaces()`
* **Oauth Server Service** `gcgov/framework-service-auth-oauth-server`
    * Require using Composer https://packagist.org/packages/gcgov/framework-service-auth-oauth-server
    * Add namespace `\gcgov\framework\services\authoauth` to `\app\app->registerFrameworkServiceNamespaces()`
* **User CRUD** `gcgov/framework-service-user-crud`
    * Require using Composer https://packagist.org/packages/gcgov/framework-service-user-crud
    * Add namespace `\gcgov\framework\services\usercrud` to `\app\app->registerFrameworkServiceNamespaces()`
