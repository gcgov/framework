<?php

namespace gcgov\framework\services\health;

use gcgov\framework\config;
use gcgov\framework\models\route;

/**
 * The framework's own routes. Unlike a Framework Service, this one is not registered by
 * the application — every application gets it, because a deploy pipeline cannot gate on an
 * endpoint that some applications chose not to have.
 *
 * These routes are merged FIRST, before Framework Services and before the application, so
 * an application that happens to define its own /health keeps working: FastRoute rejects
 * duplicate route definitions, so any collision surfaces at boot rather than in production.
 */
final class router implements \gcgov\framework\interfaces\router {

	public static function _before(): void {
	}


	public static function _after(): void {
	}


	/**
	 * @return \gcgov\framework\models\route[]
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function getRoutes(): array {
		$basePath = rtrim( config::getBasePath(), '/' );

		return [
			new route( 'GET', $basePath . '/health', '\gcgov\framework\services\health\controllers\health', 'live', false, description: 'Liveness: the process is able to serve. No I/O.' ),
			new route( 'GET', $basePath . '/health/ready', '\gcgov\framework\services\health\controllers\health', 'ready', false, description: 'Readiness: dependencies reachable. 503 when not.' ),
		];
	}


	/**
	 * Health checks are unauthenticated by necessity — the prober is a container runtime
	 * or a load balancer, neither of which holds a token. They expose no application data:
	 * a status, the deployed version, and whether each dependency answered.
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
		return true;
	}

}
