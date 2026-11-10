<?php

namespace gcgov\framework\interfaces\router;


/**
 * Opt out of the Framework Service auth guards for particular routes.
 *
 * Implement this on \app\router when the application authenticates some routes itself
 * and the enabled authentication service must not also run for them. The application's
 * own authentication() still runs; only the service guards are skipped.
 *
 * This opts out of AUTHENTICATION, never of authorization. A route's requiredRoles are
 * enforced by {@see \gcgov\framework\router::assertRequiredRoles()} after the whole guard
 * chain, so they hold for opted-out routes too. The practical consequence: a route that
 * declares requiredRoles and opts out must establish the caller itself, with
 * `\gcgov\framework\services\request::getAuthUser()->setFromUser( $user )`. Without that
 * there is no user to check the roles against and the request is refused with a 401.
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
