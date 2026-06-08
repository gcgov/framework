<?php

namespace gcgov\framework\services\mongodb\tools;


final class metaAttributeCache {

	/** @var array<string, array<string, \gcgov\framework\services\mongodb\models\_meta\uiField>> */
	private static array $fields = [];

	/** @var array<string, array<string, string>> */
	private static array $labels = [];


	/**
	 * @param array<string, string> $labels
	 * @param array<string, \gcgov\framework\services\mongodb\models\_meta\uiField> $fields
	 */
	public static function set( string $className, array $labels, array $fields ): void {
		self::$fields[ $className ] = $fields;
		self::$labels[ $className ] = $labels;
	}

	/**
	 * @return array<string, \gcgov\framework\services\mongodb\models\_meta\uiField>|null
	 */
	public static function getFields( string $className ): ?array {
		if( isset( self::$fields[ $className ] ) ) {
			return self::$fields[ $className ];
		}
		return null;
	}


	/**
	 * @return array<string, string>|null
	 */
	public static function getLabels( string $className ): ?array {
		if( isset( self::$labels[ $className ] ) ) {
			return self::$labels[ $className ];
		}
		return null;
	}

	/**
	 * @return array<string, array<string, \gcgov\framework\services\mongodb\models\_meta\uiField>>
	 */
	public static function getAllFields(): array {
		return self::$fields;
	}


	/**
	 * @return array<string, array<string, string>>
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
	 *
	 * @return string[]
	 */
	final public function __sleep(): array {
		return [];
	}

	/**
	 * Avoid unserialize instance
	 */
	final public function __wakeup() {
	}

}