<?php

namespace gcgov\framework\services\mongodb;


use JetBrains\PhpStorm\Pure;


final class typeHelpers {

	#[Pure]
	public static function classNameToFqn( $className ) : string {
		$className = ltrim( $className, '\\' );

		return '\\' . $className;
	}


	/**
	 * @param  string  $docComment  Doc comment block to parse (refflection getDocComment)
	 *
	 * @return string
	 */
	public static function getVarTypeFromDocComment( string $docComment ) : string {
		$matches = [];

		preg_match( '/@var ([^ \[\]]+)(\[])?/', $docComment, $matches );

		if( count( $matches ) > 0 ) {
			return $matches[ 1 ];
		}

		return 'array';
	}

}