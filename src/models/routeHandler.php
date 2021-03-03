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

	/** @var string[] If authentication is required, the user must have all of these roles to get to route */
	public array $requiredRoles = [];


	public function __construct( string $class, string $method, bool $authentication = false, array $requiredRoles=[], array $arguments = [] ) {

		$this->class          = $class;
		$this->method         = $method;
		$this->authentication = $authentication;
		$this->arguments      = $arguments;
		$this->requiredRoles   = $requiredRoles;

	}

}