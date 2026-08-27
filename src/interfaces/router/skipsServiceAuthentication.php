<?php

namespace gcgov\framework\interfaces\router;


/**
 * Opt out of the Framework Service auth guards for particular routes.
 *
 * Implement this on \app\router when the application authenticates some routes itself
 * and the enabled authentication service must not also run for them. The application's
 * own authentication() still runs; only the service guards are skipped.
 *
 * This was previously duck-typed — the framework looked for the method with
 * method_exists() and no interface declared it, so neither PHPStan nor an IDE could see
 * it and a typo in the name silently disabled the opt-out.
 */
interface skipsServiceAuthentication {

	/**
	 * Return false to skip the Framework Service auth guards for this route.
	 *
	 * @param  \gcgov\framework\models\routeHandler  $routeHandler
	 *
	 * @return bool
	 */
	public function getRunFrameworkServiceRouteAuthentication( \gcgov\framework\models\routeHandler $routeHandler ) : bool;
}
