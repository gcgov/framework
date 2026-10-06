<?php

namespace gcgov\framework\services\health;

use gcgov\framework\config;
use gcgov\framework\models\route;

/**
 * The framework's own routes. Unlike a Framework Service, this one is not registered by
 * the application — every application gets it, because a deploy pipeline cannot gate on an
 * endpoint that some applications chose not to have.
 *
 * These routes are merged FIRST, before Framework Services and before the application, and
 * router::getRoutes() drops a framework route the application also defines — so an
 * application that happens to define its own /health keeps both that route and the rest of
 * its surface. The override is logged.
 */
final class router implements \gcgov\framework\interfaces\router {

	/**
	 * @return \gcgov\framework\models\route[]
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function getRoutes(): array {
		$basePath = config::getRoutePrefix();

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
