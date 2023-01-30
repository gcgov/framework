<?php

namespace gcgov\framework\services\mongodb\tools;

use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheClass;
use gcgov\framework\services\mongodb\typeHelpers;

final class reflectionCache {


	/** @var \gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheClass[]  */
	private static array $reflectionCacheClasses = [];


	/**
	 * @throws \ReflectionException
	 */
	public static function getReflectionClass( string $className ): reflectionCacheClass {

		//get the called class name
		$classFqn = typeHelpers::classNameToFqn( $className );

		if(!isset(self::$reflectionCacheClasses[ $classFqn ])) {
			$reflectionClass = new reflectionCacheClass( $classFqn );
			self::$reflectionCacheClasses[ $classFqn ] = $reflectionClass;
		}

		return self::$reflectionCacheClasses[ $classFqn ];

	}
}