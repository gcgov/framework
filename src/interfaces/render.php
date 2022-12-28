<?php

namespace gcgov\framework\interfaces;


use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerViewResponse;


interface render extends lifecycle\before, lifecycle\after {

	/**
	 * Controllers are expected to capture model exceptions and convert them into controller exceptions
	 *      but this renderer is provided in the event that a model exception bubbles all the way up to
	 *      the framework renderer
	 *
	 * @param  \gcgov\framework\exceptions\modelException  $e
	 *
	 * @return \gcgov\framework\interfaces\_controllerDataResponse|controllerViewResponse
	 */
	public static function processModelException( \gcgov\framework\exceptions\modelException $e ) : \gcgov\framework\interfaces\_controllerDataResponse|controllerViewResponse;


	/**
	 * @param  \gcgov\framework\exceptions\controllerException  $e
	 *
	 * @return \gcgov\framework\interfaces\_controllerDataResponse|\gcgov\framework\models\controllerViewResponse
	 */
	public static function processControllerException( \gcgov\framework\exceptions\controllerException $e ) : \gcgov\framework\interfaces\_controllerDataResponse|controllerViewResponse;


	/**
	 * @param  \gcgov\framework\exceptions\routeException  $e
	 *
	 * @return \gcgov\framework\interfaces\_controllerDataResponse|\gcgov\framework\models\controllerViewResponse
	 */
	public static function processRouteException( \gcgov\framework\exceptions\routeException $e ) : \gcgov\framework\interfaces\_controllerDataResponse|controllerViewResponse;


	/**
	 * @param  \Exception|\Error|\ErrorException  $e
	 *
	 * @return \gcgov\framework\interfaces\_controllerDataResponse|\gcgov\framework\models\controllerViewResponse
	 */
	public static function processSystemErrorException( \Exception | \Error | \ErrorException $e ) : \gcgov\framework\interfaces\_controllerDataResponse|controllerViewResponse;

}