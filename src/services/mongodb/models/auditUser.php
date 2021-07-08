<?php

namespace gcgov\framework\services\mongodb\models;


use MongoDB\BSON\ObjectId;


final class auditUser {

	private static \gcgov\framework\services\mongodb\models\auditUser $instance;

	public ?ObjectId $userId = null;

	public string $name = '';

	/**
	 * Private constructor to force the singleton pattern
	 */
	private function __construct() {
	}

	// The object is created from within the class itself
	// only if the class has no instance.
	public static function getInstance() : auditUser {
		if( !isset( self::$instance ) || self::$instance === null ) {
			self::$instance = new auditUser();
		}

		return self::$instance;
	}
}