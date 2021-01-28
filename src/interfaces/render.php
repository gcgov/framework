<?php

namespace gcgov\framework\interfaces;


interface render extends lifecycle\before, lifecycle\after {

	public static function processControllerException( \gcgov\framework\exceptions\controllerException $e ) : array;
	public static function processRouteException( \gcgov\framework\exceptions\routeException $e ) : array;
	public static function processSystemErrorException( \Exception | \Error | \ErrorException $e ) : array;

}