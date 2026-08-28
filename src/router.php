<?php
namespace gcgov\framework;


use gcgov\framework\exceptions\routeException;
use gcgov\framework\services\log;

final class router {

	private \gcgov\framework\interfaces\appRouter $appRouter;

	/** @var \gcgov\framework\interfaces\router[] $serviceRouters  */
	private array $serviceRouters = [];

	/**
	 * Framework Services are declared in config.json's `services` section. Each is
	 * constructed here only if its block is present, and is handed its own typed
	 * configuration — there is no discovery step and no service configures itself from a
	 * singleton the application had to remember to tweak in \app\app::_before().
	 *
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function __construct() {
		if(config::getLogging()->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- constructing framework\router' );
		}

		// The framework's own routes (health checks) come first and are not opt-in: a
		// deploy pipeline cannot gate on an endpoint an application chose not to have.
		$this->serviceRouters[] = new \gcgov\framework\services\health\router();

		$services = config::getServices();

		if( $services->auth!==null ) {
			if(config::getLogging()->lifecycle) {
				log::debug( 'Framework Lifecycle', '-Router- enable auth service (' . $services->auth->provider . ')' );
			}
			$this->serviceRouters[] = new \gcgov\framework\services\auth\router( $services->auth );
		}

		if( $services->userCrud!==null ) {
			if(config::getLogging()->lifecycle) {
				log::debug( 'Framework Lifecycle', '-Router- enable userCrud service' );
			}
			$this->serviceRouters[] = new \gcgov\framework\services\userCrud\router();
		}

		if( $services->documentation!==null ) {
			if(config::getLogging()->lifecycle) {
				log::debug( 'Framework Lifecycle', '-Router- enable documentation service' );
			}
			$this->serviceRouters[] = new \gcgov\framework\services\documentation\router();
		}

		if(config::getLogging()->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- create \app\router' );
		}
		$this->appRouter = new \app\router();
	}


	/**
	 * @return \gcgov\framework\models\routeHandler
	 * @throws \gcgov\framework\exceptions\routeException
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function route(): \gcgov\framework\models\routeHandler {
		if(config::getLogging()->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- running framework\router route()' );
		}

		//get all routes
		$routes = $this->getRoutes();

		// Refuse to serve routes that believe they are protected but are not.
		self::assertAuthenticationIsProvided( $routes, config::getServices()->auth!==null, $this->appRouter->providesAuthentication() );

		//map routes to \FastRoute dispatcher
		$routeDispatcher = \FastRoute\simpleDispatcher( function( \FastRoute\RouteCollector $r ) use ( $routes ) {
			foreach( $routes as $route ) {
				$r->addRoute( $route->httpMethod, $route->route, new \gcgov\framework\models\routeHandler( $route->class, $route->method, $route->authentication, $route->requiredRoles, $route->allowShortLivedUrlTokens ) );
			}
		} );

		if(config::getLogging()->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- determine route' );
		}
		$routeInfo = $routeDispatcher->dispatch( $this->getHttpMethod(), $this->getUri() );
		switch( $routeInfo[ 0 ] ) {
			case \FastRoute\Dispatcher::NOT_FOUND:
				// ... 404 Not Found
				throw new \gcgov\framework\exceptions\routeException ( 'URL Not Found', 404 );
			case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
				// ... 405 Method Not Allowed
				throw new \gcgov\framework\exceptions\routeException ( 'Method Not Allowed', 405 );
			case \FastRoute\Dispatcher::FOUND:
				if(config::getLogging()->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- found matching route' );
				}
				//build route handler to return to the framework renderer
				/** @var \gcgov\framework\models\routeHandler $routeHandler */
				$routeHandler            = $routeInfo[ 1 ];
				$routeHandler->arguments = $routeInfo[ 2 ];

				if(config::getLogging()->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- running framework authentication' );
				}
				if( !$routeHandler->authentication ) {
					if(config::getLogging()->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- no authentication required for route' );
					}
					return $routeHandler;
				}

				if(config::getLogging()->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- run app\router authentication()' );
				}
				$appAllowRoute = $this->appRouter->authentication( $routeHandler );
				if( !$appAllowRoute ) {
					if(config::getLogging()->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- app\router authentication() returned false; raising route exception' );
					}
					throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
				}

				$runServiceRouting = true;
				if($this->appRouter instanceof \gcgov\framework\interfaces\router\skipsServiceAuthentication) {
					$runServiceRouting = $this->appRouter->getRunFrameworkServiceRouteAuthentication( $routeHandler );
				}
				if($runServiceRouting) {
					if(config::getLogging()->lifecycle) {
						log::debug( 'Framework Lifecycle', '-Router- run service routers authentication()' );
					}
					foreach($this->serviceRouters as $serviceRouter) {
						if(config::getLogging()->lifecycle) {
							log::debug( 'Framework Lifecycle', '-Router- run ' . get_class( $serviceRouter ) . ' authentication()' );
						}
						$serviceAllowRoute = $serviceRouter->authentication( $routeHandler );
						if(!$serviceAllowRoute) {
							if(config::getLogging()->lifecycle) {
								log::debug( 'Framework Lifecycle', '-Router- ' . get_class( $serviceRouter ) . ' authentication() returned false; raising route exception' );
							}
							throw new \gcgov\framework\exceptions\routeException ( 'Authentication failed', 401 );
						}
					}
				}

				if(config::getLogging()->lifecycle) {
					log::debug( 'Framework Lifecycle', '-Router- return route handler to framework\framework' );
				}
				//return rendered
				return $routeHandler;
		}

		http_response_code( 500 );
		throw new \gcgov\framework\exceptions\routeException( 'Routing failed', 500 );

	}


	/**
	 * Routes that declare authentication:true are only actually guarded by an
	 * authentication service, or by an application that authenticates its own routes.
	 * With neither, \app\router::authentication() is the only guard left — and the
	 * scaffolded implementation of it returns true for everyone, so those routes would be
	 * open to the world while looking protected in the route table.
	 *
	 * Refusing to serve is the only safe reading: a configuration that cannot protect what
	 * it claims to protect is a broken configuration, not a permissive one.
	 *
	 * @param  \gcgov\framework\models\route[]  $routes
	 *
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function assertAuthenticationIsProvided( array $routes, bool $authServiceEnabled, bool $appProvidesAuthentication ): void {
		if( $authServiceEnabled || $appProvidesAuthentication ) {
			return;
		}

		$unguarded = [];
		foreach( $routes as $route ) {
			if( $route->authentication ) {
				$unguarded[] = ( is_array( $route->httpMethod ) ? implode( '|', $route->httpMethod ) : $route->httpMethod ) . ' ' . $route->route;
			}
		}

		if( count( $unguarded )===0 ) {
			return;
		}

		throw new \gcgov\framework\exceptions\configException( count( $unguarded ) . ' route(s) require authentication but no authentication service is enabled: ' . implode( ', ', $unguarded ) . '. Enable one by adding a "services": { "auth": { "provider": "oauth" } } block to config.json, or — if \app\router authenticates these routes itself — have it implement \gcgov\framework\interfaces\appRouter::providesAuthentication() returning true.', 500 );
	}


	/**
	 * Build the full merged route table (framework routes first, then enabled Framework
	 * Services, then the application) without dispatching a request. Used by the gf CLI to
	 * enumerate routes.
	 *
	 * Deliberately does not run assertAuthenticationIsProvided(): enumerating the routes of
	 * a misconfigured application is exactly when that listing is most useful.
	 *
	 * @return \gcgov\framework\models\route[]
	 * @throws \gcgov\framework\exceptions\routeException
	 * @throws \gcgov\framework\exceptions\configException Missing/invalid {root}/config.json
	 */
	public static function getMergedRoutes(): array {
		return ( new self() )->getRoutes();
	}


	/**
	 * @return \gcgov\framework\models\route[]
	 */
	private function getRoutes(): array {
		$serviceRoutes = [];

		foreach($this->serviceRouters as $serviceRouter) {
			if(config::getLogging()->lifecycle) {
				log::debug( 'Framework Lifecycle', '-Router- get service routes' );
			}
			$serviceRoutes = array_merge( $serviceRoutes, $serviceRouter->getRoutes() );
		}

		if(config::getLogging()->lifecycle) {
			log::debug( 'Framework Lifecycle', '-Router- get app routes' );
		}
		$appRoutes = $this->appRouter->getRoutes();

		// Where the application defines a route the framework already registers, the
		// application wins and the framework's is dropped.
		//
		// FastRoute throws BadRouteException on a duplicate (method, pattern), and that
		// exception is neither a routeException nor a configException — so a v6 application
		// upgrading with its own /health did not lose /health, it lost EVERY route, with an
		// empty 500 on every url. The health router's docblock already promised that such
		// an application "keeps working"; nothing implemented it. Overriding is logged
		// rather than silent, because a route disappearing from the framework's surface is
		// worth noticing.
		$appKeys = [];
		foreach( $appRoutes as $appRoute ) {
			foreach( self::routeKeys( $appRoute ) as $key ) {
				$appKeys[ $key ] = true;
			}
		}

		$routes = [];
		foreach( $serviceRoutes as $serviceRoute ) {
			$overridden = false;
			foreach( self::routeKeys( $serviceRoute ) as $key ) {
				if( isset( $appKeys[ $key ] ) ) {
					$overridden = true;
					break;
				}
			}

			if( $overridden ) {
				log::notice( 'Framework Lifecycle', '-Router- \app\router defines "' . $serviceRoute->route . '"; the framework route of the same name is not registered' );
				continue;
			}

			$routes[] = $serviceRoute;
		}

		return array_merge( $routes, $appRoutes );
	}


	/**
	 * The (method, pattern) pairs a route occupies. httpMethod is string|array, and a route
	 * registered for several methods collides on each of them independently.
	 *
	 * @return string[]
	 */
	private static function routeKeys( \gcgov\framework\models\route $route ): array {
		$keys = [];
		foreach( (array)$route->httpMethod as $httpMethod ) {
			$keys[] = strtoupper( (string)$httpMethod ) . ' ' . $route->route;
		}

		return $keys;
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
