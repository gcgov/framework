<?php

namespace gcgov\framework\interfaces;

abstract class singleton {

	private static array $instances = [];

	private function __construct() {
	}

	final public static function getInstance() {
		$calledClass = get_called_class();

		if( !isset( self::$instances[ $calledClass ] ) ) {
			self::$instances[ $calledClass ] = new $calledClass();
		}

		return self::$instances[ $calledClass ];
	}

	/**
	 * Avoid clone instance
	 */
	final public function __clone() {
	}

	/**
	 * Avoid serialize instance
	 */
	final public function __sleep() {
	}

	/**
	 * Avoid unserialize instance
	 */
	final public  function __wakeup() {
	}

}