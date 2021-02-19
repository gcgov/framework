<?php
namespace gcgov\framework\services\mongodb\exceptions;


use JetBrains\PhpStorm\Pure;


/**
 * Class mongodbException
 * @package gcgov\framework\services\mongodb\exceptions
 */
class databaseException extends \LogicException {

	#[Pure]
	public function __construct( $message, $code = 0, \Exception $previous = null ) {

		// make sure everything is assigned properly
		parent::__construct( $message, $code, $previous );
	}

}