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
