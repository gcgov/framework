<?php

namespace gcgov\framework\services\mongodb;

/**
 * Base class for all models to extend
 * @package gcgov\framework\services\mongodb
 */
abstract class model
	extends \gcgov\framework\services\mongodb\factory {

	private static bool $_collectionIndexesFetched = false;

	/** @var \MongoDB\Model\IndexInfo[] */
	private static array $_collectionIndexes = [];

	/**
	 * @return string
	 */
	public static function _getCollectionName(): string {
		$classFqn = get_called_class();
		if( defined( $classFqn . '::_COLLECTION' ) ) {
			return $classFqn::_COLLECTION;
		}
		elseif( strrpos( $classFqn, '\\' )!==false ) {
			return substr( $classFqn, strrpos( $classFqn, '\\' ) + 1 );
		}

		return $classFqn;
	}


	/**
	 * @param bool $capitalize (optional) Capitalize the first letter of the response? Default: false
	 * @param bool $plural     (optional) Return the plural form? Default: false
	 *
	 * @return string
	 */
	public static function _getHumanName( bool $capitalize = false, bool $plural = false ): string {
		$classFqn = get_called_class();

		$name = $classFqn;

		if( $plural && defined( $classFqn . '::_HUMAN_PLURAL' ) ) {
			$name = $classFqn::_HUMAN_PLURAL;
		}
		elseif( !$plural && defined( $classFqn . '::_HUMAN' ) ) {
			$name = $classFqn::_HUMAN;
		}
		elseif( strrpos( $classFqn, '\\' )!==false ) {
			$name = substr( $classFqn, strrpos( $classFqn, '\\' ) + 1 );
		}

		if( $capitalize ) {
			return ucfirst( $name );
		}

		return $name;
	}


	/**
	 * @return \MongoDB\Model\IndexInfo[]
	 */
	public static function getIndexes(): array {
		if( !self::$_collectionIndexesFetched ) {
			$collectionName = static::_getCollectionName();
			$mdb     = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$indexesIterator = $mdb->db->$collectionName->listIndexes();

			for( $indexesIterator->rewind(); true; $indexesIterator->next() ) {
				self::$_collectionIndexesFetched[] = $indexesIterator->current();
			}
		}

		return self::$_collectionIndexes;
	}

}