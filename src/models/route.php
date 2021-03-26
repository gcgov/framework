<?php

namespace gcgov\framework\models;


/**
 * Model for \appConfig\router to define URL routes
 * Utilized by \gcgov\framework\router and \appConfig\router
 * @package \gcgov\framework\models
 */
class route {

	/** @var string|string[] HTTP Method for route */
	public string|array $httpMethod = '';

	/**
	 * @var string Example: "/organization"
	 * @see https://github.com/nikic/FastRoute
	 */
	public string $route = '';

	/** @var string Class to instantiate */
	public string $class = '';

	/** @var string Method inside $class to call */
	public string $method = '';

	/** @var bool Authentication is required */
	public bool $authentication = false;

	/** @var string[] If authentication is required, the user must have all of these roles to get to route */
	public array $requiredRoles = [];


	/**
	 * Create a route
	 *
	 * @param  string|array  $httpMethod      GET, POST, PUT, DELETE
	 * @param  string        $route           URL to capture. Ie: '/widgets/{_id}'. See https://github.com/nikic/FastRoute/ for route pattern matching details
	 * @param  string        $class           Fully qualified class name to initialize when this url is triggered. Ie: '\app\controllers\widget'
	 * @param  string        $method          Method inside the $class to call when this URL is triggered. Ie: 'getOne'. Method must have paramters that match the route pattern placeholders. In this example, getOne method must accept one parameter. Ie: getOne( string  $_id )
	 * @param  bool          $authentication  Whether authentication is required to access this route. Functionality to respond to this must be implemented in \app\router\authentication()
	 * @param  array         $requiredRoles   If authentication is required, the roles required of the use to access this route. Functionality to respond to this must be implemented in \app\router\authentication(). If not using roles for a route or at all, just skip including this parameter.
	 */
	public function __construct( string|array $httpMethod = '', string $route = '', string $class = '', string $method = '', bool $authentication = false, array $requiredRoles = [] ) {
		$this->httpMethod     = $httpMethod;
		$this->route          = $route;
		$this->class          = $class;
		$this->method         = $method;
		$this->authentication = $authentication;
		$this->requiredRoles  = $requiredRoles;
	}

}