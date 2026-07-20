<?php

namespace gcgov\framework\cli;

use gcgov\framework\models\route;

/**
 * Enumerates an application's CLI routes (routes registered with HTTP method 'CLI')
 * without running the request lifecycle.
 */
final class routeCatalog {

	/**
	 * @return \gcgov\framework\models\route[] Routes whose httpMethod is or contains 'CLI', sorted by path
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function getCliRoutes( appContext $context ): array {
		$routes = self::getAllRoutes( $context );

		$cliRoutes = array_values( array_filter( $routes, function( route $route ) {
			$methods = is_array( $route->httpMethod ) ? $route->httpMethod : [ $route->httpMethod ];

			return in_array( 'CLI', array_map( 'strtoupper', $methods ), true );
		} ) );

		usort( $cliRoutes, fn( route $a, route $b ) => strcmp( $a->route, $b->route ) );

		return $cliRoutes;
	}


	/**
	 * @return \gcgov\framework\models\route[]
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function getAllRoutes( appContext $context ): array {
		$context->assertAppLoadable();

		try {
			return \gcgov\framework\router::getMergedRoutes( $context->getServiceNamespaces() );
		}
		catch( \gcgov\framework\exceptions\configException $e ) {
			throw new cliException( 'Could not load routes: ' . $e->getMessage() . ' Run `gf env <environment>` to activate an environment first.', 0, $e );
		}
		catch( \gcgov\framework\exceptions\routeException $e ) {
			throw new cliException( 'Could not load routes: ' . $e->getMessage(), 0, $e );
		}
	}

}
