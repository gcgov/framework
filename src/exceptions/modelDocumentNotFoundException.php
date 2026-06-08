<?php
namespace gcgov\framework\exceptions;


class modelDocumentNotFoundException extends modelException {

	// Redefine the exception so message isn't optional

	/** @noinspection PhpPureAttributeCanBeAddedInspection */
	public function __construct( $message, ?\Throwable $previous = null ) {

		// make sure everything is assigned properly
		parent::__construct( $message, 404, $previous );
	}

}
