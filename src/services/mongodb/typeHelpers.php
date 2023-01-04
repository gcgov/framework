<?php

namespace gcgov\framework\services\mongodb;

final class typeHelpers {

	/** @var string[] $classNameToFqnConversionCache */
	private static array $classNameToFqnConversionCache = [];
	private static array $varTypeFromDocCommentCache    = [];


	public static function classNameToFqn( $className ): string {
		if( !isset( self::$classNameToFqnConversionCache[ $className ] ) ) {
			self::$classNameToFqnConversionCache[ $className ] = '\\' . ltrim( $className, '\\' );
		}
		return self::$classNameToFqnConversionCache[ $className ];
	}


	/**
	 * @param string $docComment Doc comment block to parse (refflection getDocComment)
	 *
	 * @return string
	 */
	public static function getVarTypeFromDocComment( string $docComment ): string {
		if( !isset( self::$varTypeFromDocCommentCache[ $docComment ] ) ) {

			$matches = [];

			preg_match( '/@var ([^ \[\]]+)(\[])?/', $docComment, $matches );

			if( count( $matches )>0 ) {
				self::$varTypeFromDocCommentCache[ $docComment ] = $matches[ 1 ];
			}

			if(empty(self::$varTypeFromDocCommentCache[ $docComment ])) {
				self::$varTypeFromDocCommentCache[ $docComment ] = 'array';
			}
		}

		return self::$varTypeFromDocCommentCache[ $docComment ];
	}

}