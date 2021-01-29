<?php

namespace gcgov\framework\interfaces;


interface router extends lifecycle\before, lifecycle\after {

	/**
	 * @return \gcgov\framework\models\route[]
	 */
	public function getRoutes() : array;


	/**
	 * @param  \gcgov\framework\models\routeHandler  $routeHandler
	 *
	 * @return bool
	 *
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ) : bool;
}