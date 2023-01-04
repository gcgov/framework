<?php

namespace gcgov\framework\services\mongodb\tools;

class sys {

	/** @var bool[] $propertyExists Multidimensional - $propertyExists[ Class FQN ][ Method Name ]=>bool */
	private static array $propertyExists = [];

	/** @var bool[] $methodExists Multidimensional - $methodExists[ Class FQN ][ Method Name ]=>bool */
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