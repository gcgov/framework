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

				self::assertRequiredRoles( $routeHandler );

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
	 * Enforce the route's declared requiredRoles.
	 *
	 * requiredRoles is declared on route and carried into routeHandler — framework-level
	 * models present on every route of every application — but the only code that ever read
	 * it was the guard inside the OPTIONAL auth service. Two supported configurations
	 * therefore declared roles that nothing checked, while looking protected in the route
	 * table, in `gf cli:list` and in review:
	 *
	 *   · no services.auth block, with \app\router::providesAuthentication() returning true.
	 *     The boot check is satisfied, and userCrud::authentication() returns true
	 *     unconditionally — so the framework's own /user routes ran with User.Read and
	 *     User.Write checked by nobody.
	 *   · any route where skipsServiceAuthentication skipped the service guards, taking the
	 *     one role check in the codebase with them.
	 *
	 * Enforcing here — after the app router and every service router have run — puts the
	 * check at the layer that declares the field, and means the answer no longer depends on
	 * which optional service happens to be enabled. The auth service's guard keeps doing
	 * what only it can do: validate the token and populate authUser.
	 *
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	private static function assertRequiredRoles( \gcgov\framework\models\routeHandler $routeHandler ): void {
		if( count( $routeHandler->requiredRoles )===0 ) {
			return;
		}

		$authUser = \gcgov\framework\services\request::getAuthUser();

		// The route names roles but nothing established a user, so there is nothing to check
		// them against. Fail closed: this is the case the boot check cannot see, because an
		// \app\router::authentication() that returns true is indistinguishable from one that
		// verified something. The detail goes to the log — it is a deployment
		// misconfiguration, not something the caller can act on.
		if( $authUser->userId==='' ) {
			log::warning( 'Framework Lifecycle', '-Router- route "' . $routeHandler->class . '::' . $routeHandler->method . '" requires role(s) ' . implode( ', ', $routeHandler->requiredRoles ) . ' but no authenticated user was established. Enable services.auth, or have the authenticator populate the request-scoped authUser via request::getAuthUser()->setFromUser().' );

			throw new routeException( 'Authentication failed', 401 );
		}

		foreach( $routeHandler->requiredRoles as $requiredRole ) {
			if( !$authUser->hasRole( $requiredRole ) ) {
				throw new routeException( 'User does not have the permission "' . $requiredRole . '" required to access this content', 403 );
			}
		}
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
		// Contradictory rather than dangerous, and independent of how the application
		// authenticates: an unauthenticated route returns before the guard chain, so its
		// roles can never be checked by anything. Warned rather than refused — such a route
		// works today with its roles inert, and failing an application's boot over a
		// declaration that never did anything is disproportionate. Behind the lifecycle
		// flag because routes are rebuilt per request: unconditional, this was one
		// identical log line per request for the life of the deployment.
		if( config::getLogging()->lifecycle ) {
			foreach( $routes as $route ) {
				if( !$route->authentication && count( $route->requiredRoles )>0 ) {
					log::warning( 'Framework Lifecycle', '-Router- route "' . $route->route . '" declares requiredRoles but authentication:false, so the roles are never checked. Set authentication:true, or drop the roles.' );
				}
			}
		}

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
		// an application "keeps working"; nothing implemented it.
		return array_merge( self::serviceRoutesNotOverridden( $serviceRoutes, $appRoutes ), $appRoutes );
	}


	/**
	 * The service routes the application's own routes do NOT override.
	 *
	 * "Override" is judged the way FastRoute judges a duplicate — by the compiled shape of
	 * the pattern, never its spelling. user/{id} and user/{_id} are the same route to the
	 * dispatcher (a placeholder's name never reaches its regex), so comparing raw pattern
	 * strings kept both registered, and BadRouteException at dispatcher build took every
	 * url down — the exact outage this filter exists to prevent. A static application
	 * route inside a variable service route's shape (user/me under the service's
	 * user/{_id}) drops the service route for the same reason: service routes register
	 * first, and FastRoute rejects a static route shadowed by an earlier variable one.
	 *
	 * Pure and public so the collision rules are testable with synthetic routes.
	 *
	 * @param  \gcgov\framework\models\route[]  $serviceRoutes
	 * @param  \gcgov\framework\models\route[]  $appRoutes
	 *
	 * @return \gcgov\framework\models\route[]
	 */
	public static function serviceRoutesNotOverridden( array $serviceRoutes, array $appRoutes ): array {
		$appKeys        = [];
		$appStaticPaths = [];
		foreach( $appRoutes as $appRoute ) {
			foreach( (array)$appRoute->httpMethod as $httpMethod ) {
				$method = strtoupper( (string)$httpMethod );
				foreach( self::patternShapes( $appRoute->route ) as $shape ) {
					$appKeys[ $method . ' ' . $shape[ 'signature' ] ] = true;
					if( $shape[ 'regex' ]===null ) {
						$appStaticPaths[ $method ][] = $shape[ 'signature' ];
					}
				}
			}
		}

		$kept = [];
		foreach( $serviceRoutes as $serviceRoute ) {
			if( self::isOverriddenBy( $serviceRoute, $appKeys, $appStaticPaths ) ) {
				if( config::getLogging()->lifecycle ) {
					// Behind the lifecycle flag: routes are rebuilt per request, and an
					// application using the override path deliberately would otherwise
					// emit one identical notice per request for the life of the deploy.
					log::notice( 'Framework Lifecycle', '-Router- \app\router defines "' . $serviceRoute->route . '"; the framework route of the same shape is not registered' );
				}
				continue;
			}

			$kept[] = $serviceRoute;
		}

		return $kept;
	}


	/**
	 * @param  array<string, true>      $appKeys         'METHOD signature' the app occupies
	 * @param  array<string, string[]>  $appStaticPaths  method => the app's static paths
	 */
	private static function isOverriddenBy( \gcgov\framework\models\route $serviceRoute, array $appKeys, array $appStaticPaths ): bool {
		foreach( (array)$serviceRoute->httpMethod as $httpMethod ) {
			$method = strtoupper( (string)$httpMethod );
			foreach( self::patternShapes( $serviceRoute->route ) as $shape ) {
				if( isset( $appKeys[ $method . ' ' . $shape[ 'signature' ] ] ) ) {
					return true;
				}
				if( $shape[ 'regex' ]!==null ) {
					foreach( $appStaticPaths[ $method ] ?? [] as $staticPath ) {
						if( preg_match( '~^' . $shape[ 'regex' ] . '$~', $staticPath )===1 ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}


	/**
	 * The shapes a FastRoute pattern occupies, one per optional-segment variant.
	 *
	 * `signature` keys the shape the way the dispatcher compiles it: literals verbatim,
	 * each placeholder reduced to {its-regex} — so user/{id} and user/{_id} share a
	 * signature while user/{id:\d+} has its own. `regex` is the anchored expression for a
	 * variant carrying placeholders (null for a purely static one), used by the
	 * static-shadowing check. A pattern the parser rejects falls back to its literal
	 * spelling: FastRoute reports the malformed pattern itself at dispatcher build.
	 *
	 * @return array{signature: string, regex: string|null}[]
	 */
	public static function patternShapes( string $pattern ): array {
		try {
			$variants = ( new \FastRoute\RouteParser\Std() )->parse( $pattern );
		}
		catch( \FastRoute\BadRouteException ) {
			return [ [ 'signature' => $pattern, 'regex' => null ] ];
		}

		$shapes = [];
		foreach( $variants as $variant ) {
			$signature = '';
			$regex     = '';
			$variable  = false;
			foreach( $variant as $part ) {
				if( is_array( $part ) ) {
					// [ placeholder name, placeholder regex ] — the name never compiles.
					$variable   = true;
					$signature .= '{' . (string)$part[ 1 ] . '}';
					$regex     .= '(' . (string)$part[ 1 ] . ')';
				}
				else {
					$signature .= (string)$part;
					$regex     .= preg_quote( (string)$part, '~' );
				}
			}

			$shapes[] = [ 'signature' => $signature, 'regex' => $variable ? $regex : null ];
		}

		return $shapes;
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
