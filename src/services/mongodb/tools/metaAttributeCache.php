<?php

namespace gcgov\framework\services\mongodb\tools;


final class metaAttributeCache {

	/** @var \gcgov\framework\services\mongodb\models\_meta\uiField[] $fields */
	private static array $fields = [];

	/** @var string[][] $labels */
	private static array $labels = [];


	public static function set( string $className, array $labels, array $fields ) {
		self::$fields[ $className ] = $fields;
		self::$labels[ $className ] = $labels;
	}

	/**
	 * @return ?\gcgov\framework\services\mongodb\models\_meta\uiField[]
	 */
	public static function getFields( string $className ): ?array {
		if( isset( self::$fields[ $className ] ) ) {
			return self::$fields[ $className ];
		}
		return null;
	}


	/**
	 * @return ?string[]
	 */
	public static function getLabels( string $className ): ?array {
		if( isset( self::$labels[ $className ] ) ) {
			return self::$labels[ $className ];
		}
		return null;
	}

	/**
	 * @return \gcgov\framework\services\mongodb\models\_meta\uiField[]
	 */
	public static function getAllFields(): array {
		return self::$fields;
	}


	/**
	 * @return string[]
	 */
	public static function getAllLabels(): array {
		return self::$labels;
	}

	/**
	 * Avoid instantiation
	 */
	final private function __construct() {
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