<?php
namespace gcgov\framework;


abstract class router {

	public function __construct() {

	}


	/**
	 * Performs actual route by calling executeRouteAndRender() and handles exceptions for displaying errors
	 *
	 * Example of what should be inside route method:
	 * <code>
	 * try {
	 *     return $this->executeRouteAndRender();
	 * }
	 * catch( \gcgov\framework\exceptions\routeException  $e ) {
	 *     \appConfig\services\api::sendError( $e->getCode(), $e->getMessage() );
	 * }
	 * </code>
	 *
	 * @return \gcgov\framework\models\routeHandler
	 */
	abstract function route() : \gcgov\framework\models\routeHandler ;


	/**
	 * @return \gcgov\framework\models\routeHandler
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	protected function executeRouteAndRender() : \gcgov\framework\models\routeHandler {

		$routes = $this->getRoutes();

		$dispatcher = \FastRoute\simpleDispatcher( function( \FastRoute\RouteCollector $r ) use ( $routes ) {

			foreach( $routes as $route ) {
				$r->addRoute( $route->httpMethod, $route->route, new \gcgov\framework\models\routeHandler( $route->class, $route->method, $route->authentication ) );
			}
		} );


		// Fetch method and URI from somewhere
		$httpMethod = $_SERVER[ 'REQUEST_METHOD' ];
		$uri        = $_SERVER[ 'REQUEST_URI' ];

		// Strip query string (?foo=bar) and decode URI
		if( false !== $pos = strpos( $uri, '?' ) ) {
			$uri = substr( $uri, 0, $pos );
		}
		$uri = rawurldecode( $uri );

		$routeInfo = $dispatcher->dispatch( $httpMethod, $uri );
		switch( $routeInfo[ 0 ] ) {
			case \FastRoute\Dispatcher::NOT_FOUND:
				// ... 404 Not Found
				throw new \gcgov\framework\exceptions\routeException ( 'Not Found', 404 );
				break;
			case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
				$allowedMethods = $routeInfo[ 1 ];
				// ... 405 Method Not Allowed
				throw new \gcgov\framework\exceptions\routeException ( 'Method Not Allowed', 405 );
				break;
			case \FastRoute\Dispatcher::FOUND:

				/** @var \gcgov\framework\models\routeHandler $routeHandler */
				$routeHandler            = $routeInfo[ 1 ];
				$routeHandler->arguments = $routeInfo[ 2 ];

				try {
					//authenticate
					//TODO: expand authentication to handle roles?
					if( $routeHandler->authentication && !$this->authentication() ) {
						throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
					}

					//return rendered
					return $routeHandler;
				}
				catch( \gcgov\framework\exceptions\controllerException | \gcgov\framework\exceptions\routeException $e ) {
					throw new \gcgov\framework\exceptions\routeException ( $e->getMessage(), $e->getCode(), $e );
				}
				break;
		}

		http_response_code( 500 );
		throw new \gcgov\framework\exceptions\routeException( 'Routing failed', 500 );

	}


	/**
	 * Defines URL routes and returns them as an array
	 *
	 * @return \gcgov\framework\models\route[]
	 */
	abstract function getRoutes() : array;


	/**
	 * Checks if user is authenticated and returns true if yes, or false if not
	 *
	 * @return bool
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	abstract function authentication() : bool;


}