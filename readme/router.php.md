# /app/router.php

```php
namespace app;


use gcgov\framework\models\route;

class router implements \gcgov\framework\interfaces\appRouter {

	public function __construct() {
	}


	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after() : void {
	}


	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before() : void {
	}


	/**
	 * @return \gcgov\framework\models\route[]
	 */
	public function getRoutes() : array {
		/** @var \gcgov\framework\models\route[] $routes */
		$routes = [];

		//if your app will not run at the root of the domain, add a prefix, for example "/api"
		$routePrepend = '';

		$routes[] = new route( 'GET', $routePrepend.'structure', '\app\controllers\structure', 'getAll', true, [ constants::ROLE_STRUCTURE_READ ] );
		$routes[] = new route( 'GET', $routePrepend.'structure/basic', '\app\controllers\structure', 'getAllBasic', true, [ constants::ROLE_STRUCTURE_READ ] );
		$routes[] = new route( 'GET', $routePrepend.'structure/{_id}', '\app\controllers\structure', 'getOne', true, [ constants::ROLE_STRUCTURE_READ ] );
		$routes[] = new route( 'POST', $routePrepend.'structure/{_id}', '\app\controllers\structure', 'save', true, [ constants::ROLE_STRUCTURE_READ, constants::ROLE_STRUCTURE_WRITE ] );
		$routes[] = new route( 'DELETE', $routePrepend.'structure/{_id}', '\app\controllers\structure', 'delete', true, [ constants::ROLE_STRUCTURE_READ, constants::ROLE_STRUCTURE_WRITE ] );

		//CLI example
//		$routes[] = new route( 'CLI', '/cli/importUpdateMinorStructuresFromCsv', '\app\controllers\cli\import', 'importUpdateMinorStructuresFromCsv', false );
//		$routes[] = new route( 'CLI', '/cli/updateStructuresFromGis', '\app\controllers\cli\import', 'updateStructuresFromGis', false );

		return $routes;
	}


	/**
	 * This method is automagically called when a route is matched that has authentication set to true
	 * 
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ) : bool {
		//if you have enabled services.auth (either provider) 
		//  it automatically adds our authentication guard
		//  you can add additional, custom authentication checks here
		//  your custom checks will run before the service authentication checks
		//  if you need to prevent the service authentication checks from running in certain situations, return false from $this->getRunFrameworkServiceRouteAuthentication()

		//otherwise, you need to validate the user against the route here

		//user has been authenticated
		return true;
	}
	
	/**
	 * Does this application authenticate its own routes?
	 *
	 * Required by \gcgov\framework\interfaces\appRouter. The framework refuses to boot
	 * when routes declare authentication:true, no authentication service is enabled, and
	 * this returns false — such routes would be reachable by anyone, because the
	 * authentication() above returns true for every caller. Return true ONLY if
	 * authentication() genuinely establishes and verifies the caller's identity.
	 */
	public function providesAuthentication() : bool {
		return false;
	}

	//optional: to prevent the Framework Service auth guards from running for some routes,
	//also implement \gcgov\framework\interfaces\router\skipsServiceAuthentication:
	//
	//    class router implements \gcgov\framework\interfaces\appRouter, \gcgov\framework\interfaces\router\skipsServiceAuthentication
	//
	//and add the method it declares. Note the $routeHandler parameter — the opt-out is
	//per route, and was duck-typed via method_exists() before the interface existed:
	//
	//public function getRunFrameworkServiceRouteAuthentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
	//    return true;
	//}

}
```
