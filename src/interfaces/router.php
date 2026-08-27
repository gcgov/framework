<?php

namespace gcgov\framework\interfaces;


/**
 * Contributes routes, and guards the ones that require authentication.
 *
 * Implemented by the framework's own routers (health, and each Framework Service the
 * application enables) and, through {@see appRouter}, by \app\router.
 *
 * Note there are no lifecycle hooks here. Only \app\router's _before()/_after() are
 * invoked by the framework, so requiring them of every router described a contract that
 * was never honoured; they live on {@see appRouter} instead.
 */
interface router {

	/**
	 * @return \gcgov\framework\models\route[]
	 */
	public function getRoutes() : array;


	/**
	 * Return false to deny the request. Throw a routeException to deny it with a specific
	 * status and message.
	 *
	 * @param  \gcgov\framework\models\routeHandler  $routeHandler
	 *
	 * @return bool
	 *
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ) : bool;
}
