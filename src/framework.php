<?php
namespace gcgov\framework;


use gcgov\framework\exceptions\routeException;

final class framework {

	public function __construct() {
	}


	/**
	 * @return string Content to be rendered
	 */
	public function runApp() : string {

		//start of lifecycle

		//appConfig
		\app\app::_before();
		// Held for the lifetime of the request, as it always has been. Since Framework
		// Services moved into config.json there is nothing left to ask it for, but an
		// application may still do work in its constructor.
		$app = new \app\app();

		//router
		\app\router::_before();
		try {
			$router = new \gcgov\framework\router();
			$routeHandler  = $router->route();
		}
		catch( routeException $e ) {
			$routeException = $e;
		}
		catch( \Throwable $e ) {
			// Routing throws several classes that are not routeException, and every one of
			// them used to escape runApp() as a bare PHP fatal — skipping \app\router::_after(),
			// the renderer and \app\app::_after(), and returning no framework error body at all:
			//
			//   configException    the fail-closed checks (authenticated routes with no auth
			//                      service, a missing config.json, an unresolved %env() reference)
			//                      — it extends \LogicException, unrelated to routeException
			//   BadRouteException  an application defining a route the framework already registers
			//   \TypeError         an \app\router that does not implement interfaces\appRouter
			//
			// Refusing loudly is the whole point of those checks, so they are rendered like any
			// other failure. The detail goes to the log and never to the client: these messages
			// carry route patterns, config file paths and the names of missing environment
			// variables. services\log falls back to stderr when config itself is what failed.
			\gcgov\framework\services\log::critical( 'Framework Lifecycle', $e->getMessage(), [ 'exception' => $e ] );
			$routeException = new routeException( 'Server configuration error', 500, $e );
		}
		\app\router::_after();

		//renderer and controller (renderer handles calling controller lifecycle methods)
		\app\renderer::_before();
		$renderer = new \gcgov\framework\renderer();
		$content  = $renderer->render( $routeHandler ?? $routeException ?? null );
		\app\renderer::_after();

		//appConfig
		\app\app::_after();

		//end of lifecycle

		return $content;
	}

}
