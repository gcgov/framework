<?php
namespace gcgov\framework;


use PHPMailer\PHPMailer\Exception;


final class router {

	private \gcgov\framework\interfaces\router $appRouter;

	public function __construct() {
		$this->appRouter = new \app\router();
	}


	/**
	 * @return \gcgov\framework\models\routeHandler
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function route() : \gcgov\framework\models\routeHandler {

		//fast router dispatcher
		$routeDispatcher = $this->buildDispatcher();

		$routeInfo = $routeDispatcher->dispatch( $this->getHttpMethod(), $this->getUri() );
		switch( $routeInfo[ 0 ] ) {
			case \FastRoute\Dispatcher::NOT_FOUND:
				// ... 404 Not Found
				throw new \gcgov\framework\exceptions\routeException ( 'URL Not Found', 404 );
				break;
			case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
				$allowedMethods = $routeInfo[ 1 ];
				// ... 405 Method Not Allowed
				throw new \gcgov\framework\exceptions\routeException ( 'Method Not Allowed', 405 );
				break;
			case \FastRoute\Dispatcher::FOUND:

				//build route handler to return to the framework renderer
				/** @var \gcgov\framework\models\routeHandler $routeHandler */
				$routeHandler            = $routeInfo[ 1 ];
				$routeHandler->arguments = $routeInfo[ 2 ];

				try {
					//authenticate if route requires it
					if( $routeHandler->authentication ) {
						if(!$this->appRouter->authentication( $routeHandler )) {
							throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
						}
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

	private function buildDispatcher() :  \FastRoute\Dispatcher {
		$routes = $this->appRouter->getRoutes();
		return \FastRoute\simpleDispatcher( function( \FastRoute\RouteCollector $r ) use ( $routes ) {

			foreach( $routes as $route ) {
				$r->addRoute( $route->httpMethod, $route->route, new \gcgov\framework\models\routeHandler( $route->class, $route->method, $route->authentication, $route->requiredRoles ) );
			}
		} );
	}

	private function getUri() : string {

		$uri        = $_SERVER[ 'REQUEST_URI' ];

		// Strip query string (?foo=bar) and decode URI
		if( false !== $pos = strpos( $uri, '?' ) ) {
			$uri = substr( $uri, 0, $pos );
		}
		$uri = rawurldecode( $uri );

		return $uri;
	}

	private function getHttpMethod() : string {
		return $_SERVER[ 'REQUEST_METHOD' ];
	}

}