<?php
namespace gcgov\framework;


use gcgov\framework\exceptions\routeException;
use gcgov\framework\services\log;
use ReflectionClass;

final class router {

	private \gcgov\framework\interfaces\router $appRouter;

	/** @var \gcgov\framework\interfaces\router[] $serviceRouters  */
	private array $serviceRouters = [];

	/** @var string[] $serviceNamespaces  */
	private array $serviceNamespaces = [];

	/**
	 * @param string[] $serviceNamespaces
	 *
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function __construct( array $serviceNamespaces ) {
		if(config::getEnvironmentConfig()->logging->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- constructing framework\router' );
			log::debug( 'Framework Lifecycle', '-Router- check for routers in services' );
		}
		foreach($serviceNamespaces as $serviceNamespace) {
			try {
				$reflectionClassOfServiceRouter = new ReflectionClass( $serviceNamespace . '\router' );
				if(config::getEnvironmentConfig()->logging->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- instantiate ' . $serviceNamespace . '\router' );
				}
				$serviceRouter = $reflectionClassOfServiceRouter->newInstance();
				if(!($serviceRouter instanceof \gcgov\framework\interfaces\router)) {
					error_log($serviceNamespace.'\router must implement \gcgov\framework\interfaces\router if it wants to be used as a router by gcgov\framework');
					continue;
				}
				$this->serviceRouters[] = $serviceRouter;
			}
			catch( \ReflectionException $e ) {
				//service does not have a router, no problem
			}
		}

		if(config::getEnvironmentConfig()->logging->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- create \app\router' );
		}
		$appRouter = new \app\router();
		if(!($appRouter instanceof \gcgov\framework\interfaces\router)) {
			error_log('\app\router must implement \gcgov\framework\interfaces\router');
			throw new routeException( '\app\router must implement \gcgov\framework\interfaces\router', 500 );
		}
		$this->appRouter = $appRouter;
	}


	/**
	 * @return \gcgov\framework\models\routeHandler
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function route(): \gcgov\framework\models\routeHandler {
		if(config::getEnvironmentConfig()->logging->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- running framework\router route()' );
		}

		//get all routes
		$routes = $this->getRoutes();

		//map routes to \FastRoute dispatcher
		$routeDispatcher = \FastRoute\simpleDispatcher( function( \FastRoute\RouteCollector $r ) use ( $routes ) {
			foreach( $routes as $route ) {
				$r->addRoute( $route->httpMethod, $route->route, new \gcgov\framework\models\routeHandler( $route->class, $route->method, $route->authentication, $route->requiredRoles, $route->allowShortLivedUrlTokens ) );
			}
		} );

		if(config::getEnvironmentConfig()->logging->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- determine route' );
		}
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
				if(config::getEnvironmentConfig()->logging->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- found matching route' );
				}
				//build route handler to return to the framework renderer
				/** @var \gcgov\framework\models\routeHandler $routeHandler */
				$routeHandler            = $routeInfo[ 1 ];
				$routeHandler->arguments = $routeInfo[ 2 ];

				if(config::getEnvironmentConfig()->logging->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- running framework authentication' );
				}
				if( !$routeHandler->authentication ) {
					if(config::getEnvironmentConfig()->logging->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- no authentication required for route' );
					}
					return $routeHandler;
				}

				if(config::getEnvironmentConfig()->logging->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- run app\router authentication()' );
				}
				$appAllowRoute = $this->appRouter->authentication( $routeHandler );
				if( !$appAllowRoute ) {
					if(config::getEnvironmentConfig()->logging->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- app\router authentication() returned false; raising route exception' );
					}
					throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
				}

				$runServiceRouting = true;
				if(method_exists($this->appRouter, 'getRunFrameworkServiceRouteAuthentication')) {
					$runServiceRouting = $this->appRouter->getRunFrameworkServiceRouteAuthentication( $routeHandler );
				}
				if($runServiceRouting) {
					if(config::getEnvironmentConfig()->logging->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- run service routers authentication()' );
					}
					foreach($this->serviceRouters as $serviceRouter) {
						if(config::getEnvironmentConfig()->logging->lifecycle) {
							log::debug( 'Framework Lifecycle', '-Router- run framework\services\\' . get_class( $serviceRouter ) . '\router authentication()' );
						}
						$serviceAllowRoute = $serviceRouter->authentication( $routeHandler );
						if(!$serviceAllowRoute) {
							if(config::getEnvironmentConfig()->logging->lifecycle) {
								log::debug( 'Framework Lifecycle', '-Router- framework\services\\' . get_class( $serviceRouter ) . '\router authentication() returned false; raising route exception' );
							}
							throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
						}
					}
				}

				if(config::getEnvironmentConfig()->logging->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- return route handler to framework\framework' );
				}
				//return rendered
				return $routeHandler;
		}

		http_response_code( 500 );
		throw new \gcgov\framework\exceptions\routeException( 'Routing failed', 500 );

	}


	/**
	 * @return \gcgov\framework\models\route[]
	 */
	private function getRoutes(): array {
		$routes = [];

		foreach($this->serviceRouters as $serviceRouter) {
			if(config::getEnvironmentConfig()->logging->lifecycle) {
				log::debug( 'Framework Lifecycle', '-Router- get service routes' );
			}
			$serviceRoutes = $serviceRouter->getRoutes();
			$routes = array_merge( $routes, $serviceRoutes );
		}

		if(config::getEnvironmentConfig()->logging->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- get app routes' );
		}
		$appRoutes = $this->appRouter->getRoutes();
		$routes = array_merge( $routes, $appRoutes );

		return $routes;
	}


	private function getUri(): string {

		$uri = $_SERVER[ 'REQUEST_URI' ];

		// Strip query string (?foo=bar) and decode URI
		if( false!==$pos = strpos( $uri, '?' ) ) {
			$uri = substr( $uri, 0, $pos );
		}
		$uri = rawurldecode( $uri );

		return $uri;
	}


	private function getHttpMethod(): string {
		return $_SERVER[ 'REQUEST_METHOD' ];
	}

}
