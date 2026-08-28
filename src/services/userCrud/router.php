<?php

namespace gcgov\framework\services\userCrud;

use gcgov\framework\config;
use gcgov\framework\models\route;

/**
 * CRUD over whatever user model the application resolves — \app\models\user if it
 * defines one, otherwise the framework's Mongo user model.
 *
 * The role names are part of this service's contract rather than a setting: an
 * application that wants different names for user administration is describing a
 * different service.
 */
class router implements \gcgov\framework\interfaces\router {

	private const CONTROLLER = '\gcgov\framework\services\userCrud\controllers\user';

	public function getRoutes(): array {
		$basePath = config::getRoutePrefix();

		return [
			new route( 'GET', $basePath . '/user', self::CONTROLLER, 'getAll', true, [ 'User.Read' ] ),
			new route( 'GET', $basePath . '/user/{_id}', self::CONTROLLER, 'getOne', true, [ 'User.Read' ] ),
			new route( 'POST', $basePath . '/user/{_id}', self::CONTROLLER, 'save', true, [ 'User.Read', 'User.Write' ] ),
			new route( 'DELETE', $basePath . '/user/{_id}', self::CONTROLLER, 'delete', true, [ 'User.Read', 'User.Write' ] )
		];
	}


	/**
	 * This service enforces nothing itself. Its routes require authentication, and the
	 * framework refuses to boot when authenticated routes exist with no authentication
	 * service enabled — so "installed but unguarded" is no longer reachable.
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
		return true;
	}

}
