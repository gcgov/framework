<?php


namespace gcgov\framework\models;


/**
 * Model for production and consumption by \gcgov\framework for acting upon \gcgov\framework\models\route
 *
 * Utilized by \gcgov\framework\router
 * @package gcgov\framework\models
 */
class routeHandler {


	public string $class          = '';

	public string $method         = '';

	public bool   $authentication = false;

	public array  $arguments      = [];


	public function __construct( string $class, string $method, bool $authentication = false, $arguments = [] ) {

		$this->class          = $class;
		$this->method         = $method;
		$this->authentication = $authentication;
		$this->arguments      = $arguments;

	}

}