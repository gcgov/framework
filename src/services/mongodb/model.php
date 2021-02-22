<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\models\_meta;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;


/**
 * Base class for all models to extend
 * @package gcgov\framework\services\mongodb
 */
abstract class model
	extends
	\gcgov\framework\services\mongodb\factory {

	public _meta $_meta;


	public function __construct() {
		$this->_meta = new _meta( get_called_class() );
	}


	public function __clone() {
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to clone ' . $calledClassFqn, 500, $e );
		}

		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			$propertyName     = $rProperty->getName();
			$rPropertyType    = $rProperty->getType();
			$propertyTypeName = $rPropertyType->getName();

			$propertyIsTypedArray = false;
			if( $propertyTypeName == 'array' ) {
				$propertyTypeName = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $propertyTypeName != 'array' ) {
					$propertyIsTypedArray = true;
				}
			}

			//try to instantiate to see if it is a type that needs dealt with
			$cloneable = false;
			try {
				$rPropertyClass = new \ReflectionClass( $propertyTypeName );
				$cloneable      = $rPropertyClass->isCloneable();
			}
			catch( \ReflectionException $e ) {
				//base type
				continue;
			}

			//if the item(s) can be cloned, clone them
			if( $cloneable && isset( $this->$propertyName ) ) {
				if( $propertyIsTypedArray ) {
					foreach( $this->$propertyName as $i => $v ) {
						$this->$propertyName[ $i ] = clone $v;
					}
				}
				else {
					error_log( $propertyTypeName );
					$this->$propertyName = clone $this->$propertyName;
				}
			}
		}
	}


	/**
	 * @return array
	 */
	#[ArrayShape( [
		'root'       => "string",
		'fieldPaths' => "string[]"
	] )]
	public static function _getTypeMap() : array {
		return self::_typeMap()->toArray();
	}


	/**
	 * @return string
	 */
	#[Pure]
	public static function _getCollectionName() : string {
		$classFqn = get_called_class();
		if( defined( $classFqn . '::_COLLECTION' ) ) {
			return $classFqn::_COLLECTION;
		}
		elseif( strrpos( $classFqn, '\\' ) !== false ) {
			return substr( $classFqn, strrpos( $classFqn, '\\' ) + 1 );
		}

		return $classFqn;
	}


	/**
	 * @param  bool  $capitalize  (optional) Capitalize the first letter of the response? Default: false
	 * @param  bool  $plural      (optional) Return the plural form? Default: false
	 *
	 * @return string
	 */
	#[Pure]
	public static function _getHumanName( bool $capitalize = false, bool $plural = false ) : string {
		$classFqn = get_called_class();

		$name = $classFqn;

		if( $plural && defined( $classFqn . '::_HUMAN_PLURAL' ) ) {
			$name = $classFqn::_HUMAN_PLURAL;
		}
		elseif( !$plural && defined( $classFqn . '::_HUMAN' ) ) {
			$name = $classFqn::_HUMAN;
		}
		elseif( strrpos( $classFqn, '\\' ) !== false ) {
			$name = substr( $classFqn, strrpos( $classFqn, '\\' ) + 1 );
		}

		if( $capitalize ) {
			return ucfirst( $name );
		}

		return $name;
	}

}