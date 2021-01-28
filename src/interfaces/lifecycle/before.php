<?php
namespace gcgov\framework\interfaces\lifecycle;


interface before {

	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before() : void ;

}