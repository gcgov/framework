<?php

namespace gcgov\framework\services\mongodb\tools;

use gcgov\framework\exceptions\serviceException;
use gcgov\framework\models\authUser;

final class typeMapCache {

	private static bool $allTypeMapsFetched = false;

	/** @var \gcgov\framework\services\mongodb\typeMap[] */
	private static array $typeMaps = [];

	/** @var \gcgov\framework\services\mongodb\typeMap[] */
	private static array $modelTypeMaps = [];

	public static function set( string $className, \gcgov\framework\services\mongodb\typeMap $typeMap ) {
		self::$typeMaps[ $className ] = $typeMap;
		if( $typeMap->model ) {
			self::$modelTypeMaps[ $typeMap->collection ]  = $typeMap;
		}
	}

	public static function get( string $className ): ?\gcgov\framework\services\mongodb\typeMap {
		if( isset( self::$typeMaps[ $className ] ) ) {
			return self::$typeMaps[ $className ];
		}
		return null;
	}

	/**
	 * @return \gcgov\framework\services\mongodb\typeMap[]
	 */
	public static function getAllModels(): array {
		return self::$modelTypeMaps;
	}

	/**
	 * Avoid instantiation
	 */
	final private function __construct() {
	}

	/**
	 * @return bool
	 */
	public static function allTypeMapsFetched(): bool {
		return self::$allTypeMapsFetched;
	}

	/**
	 * @param bool $allTypeMapsFetched
	 */
	public static function setAllTypeMapsFetched( bool $allTypeMapsFetched ): void {
		self::$allTypeMapsFetched = $allTypeMapsFetched;
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
	final public function __wakeup() {
	}

}