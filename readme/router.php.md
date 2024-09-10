# /app/router.php

```php
namespace app;


use gcgov\framework\models\route;

class router implements \gcgov\framework\interfaces\router {

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

		//if your app will not run at the root of the domain, add the relative url to the app: ie: if your site will serve from http://example.com/api, $routePrepend="/api";
		$routePrepend = '/bridges/api/';

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
		//if you are utilizing the \gcgov\framework\services\authoauth service or \gcgov\framework\services\authmsfront 
		//  it automatically adds our authentication guard
		//  you can add additional, custom authentication checks here
		//  your custom checks will run before the service authentication checks
		//  if you need to prevent the service authentication checks from running in certain situations, return false from $this->getRunFrameworkServiceRouteAuthentication()

		//otherwise, you need to validate the user against the route here

		//user has been authenticated
		return true;
	}
	
	//optional method that can be added to prevent the service authentication checks from running
	//private $runFrameworkServiceRouteAuthentication = true;
	//public function getRunFrameworkServiceRouteAuthentication(): bool {
	//    return $this->runFrameworkServiceRouteAuthentication;
	//}

}
```
