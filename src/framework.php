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
		$app = new \app\app();

		//router
		\app\router::_before();
		$router = new \gcgov\framework\router();
		try {
			$routeHandler  = $router->route();
		}
		catch( routeException $e ) {
			$routeException = $e;
		}
		\app\router::_after();

		//renderer and controller (renderer handles calling controller lifecycle methods)
		\app\renderer::_before();
		$renderer = new \gcgov\framework\renderer();
		$content  = $renderer->render( $routeHandler ?? $routeException );
		\app\renderer::_after();

		//appConfig
		\app\app::_after();

		//end of lifecycle

		return $content;
	}

}