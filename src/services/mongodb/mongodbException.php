<?php
namespace gcgov\framework\services\mongodb;


use JetBrains\PhpStorm\Pure;


class mongodbException extends \LogicException {

	#[Pure]
	public function __construct( $message, $code = 0, \Exception $previous = null ) {

		// make sure everything is assigned properly
		parent::__construct( $message, $code, $previous );
	}

}