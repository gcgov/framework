<?php
namespace gcgov\framework\interfaces\lifecycle;


interface after {

	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after() : void ;

}