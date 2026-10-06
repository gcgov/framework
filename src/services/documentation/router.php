<?php

namespace gcgov\framework\services\documentation;

use gcgov\framework\config;
use gcgov\framework\models\route;

/**
 * Serves the OpenAPI document generated from the annotations on the application's and
 * the framework's own source.
 *
 * Unauthenticated by design: the document describes the API surface, and a client that
 * cannot read it before authenticating cannot discover how to authenticate.
 */
class router implements \gcgov\framework\interfaces\router {

	public function getRoutes(): array {
		return [
			new route( 'GET', config::getRoutePrefix() . '/documentation.yaml', '\gcgov\framework\services\documentation\controllers\documentation', 'yaml', false, description: 'OpenAPI document generated from source annotations.' )
		];
	}


	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
		return true;
	}

}
