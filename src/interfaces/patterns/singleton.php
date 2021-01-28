<?php


namespace gcgov\framework\interfaces\patterns;


interface singleton {

	/**
	 *
	 * Example:
	 * <code>
	 * if( !isset( self::$instance ) || self::$instance === null ) {
	 * self::$instance = new {class}();
	 * }
	 * return self::$instance;
	 * </code>
	 */
	public static function  getInstance();

}