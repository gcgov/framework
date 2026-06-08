<?php

namespace gcgov\framework\services\mongodb\tools;

class sys {

	/** @var array<string, array<string, bool>> Class FQN => property name => exists */
	private static array $propertyExists = [];

	/** @var array<string, array<string, bool>> Class FQN => method name => exists */
	private static array $methodExists = [];


	public static function methodExists( string $classFQN, string $methodName ): bool {
		if( !isset( self::$methodExists[ $classFQN ] ) || !isset( self::$methodExists[ $classFQN ][ $methodName ] ) ) {
			self::setMethodExists( $classFQN, $methodName, method_exists( $classFQN, $methodName ) );
		}
		return self::$methodExists[ $classFQN ][ $methodName ];
	}


	protected static function setMethodExists( string $classFQN, string $methodName, bool $methodExists ): void {
		if( !isset( self::$methodExists[ $classFQN ] ) ) {
			self::$methodExists[ $classFQN ] = [];
		}
		self::$methodExists[ $classFQN ][ $methodName ] = $methodExists;

	}


	public static function propertyExists( string $classFQN, string $propertyName ): bool {
		if( !isset( self::$propertyExists[ $classFQN ] ) || !isset( self::$propertyExists[ $classFQN ][ $propertyName ] ) ) {
			self::setPropertyExists( $classFQN, $propertyName, property_exists( $classFQN, $propertyName ) );
		}
		return self::$propertyExists[ $classFQN ][ $propertyName ];
	}


	protected static function setPropertyExists( string $classFQN, string $propertyName, bool $propertyExists ): void {
		if( !isset( self::$propertyExists[ $classFQN ] ) ) {
			self::$propertyExists[ $classFQN ] = [];
		}
		self::$propertyExists[ $classFQN ][ $propertyName ] = $propertyExists;

	}

}