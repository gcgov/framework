<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\models\authUser;
use gcgov\framework\services\mongodb\attributes\includeMeta;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
use gcgov\framework\services\mongodb\attributes\redact;
use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\models\_meta;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Deprecated;

abstract class embeddable
	extends \andrewsauder\jsonDeserialize\jsonDeserialize
	implements \MongoDB\BSON\Persistable {

	#[excludeBsonSerialize]
	#[excludeBsonUnserialize]
	#[excludeJsonDeserialize]
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
			if( $propertyTypeName=='array' ) {
				$propertyTypeName = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $propertyTypeName!='array' ) {
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
					$this->$propertyName = clone $this->$propertyName;
				}
			}
		}
	}


	protected function _beforeJsonSerialize(): void {
		if( !isset( $this->_meta ) ) {
			$this->_meta = new _meta( get_called_class() );
		}
	}


	protected function _afterJsonSerialize( array $export ): array {
		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$reflectionClass = new \ReflectionClass( $calledClassFqn );

			$classIncludeMetaAttributes = $reflectionClass->getAttributes( includeMeta::class );
			foreach( $classIncludeMetaAttributes as $classIncludeMetaAttribute ) {
				$includeMetaAttributeInstance = $classIncludeMetaAttribute->newInstance();
				if( !$includeMetaAttributeInstance->includeMeta ) {
					unset( $export[ '_meta' ] );
				}
			}

			foreach( $reflectionClass->getProperties() as $property ) {

				//get all attributes for this property
				$propertyAttributes = $property->getAttributes( redact::class );
				foreach( $propertyAttributes as $propertyAttribute ) {
					$authUser                = authUser::getInstance();
					$redactAttributeInstance = $propertyAttribute->newInstance();
					if( count( $redactAttributeInstance->redactIfUserHasAnyRoles )===0 && count( $redactAttributeInstance->redactIfUserHasAllRoles )===0 ) {
						unset( $export[ $property->getName() ] );
					}
					elseif( count( $redactAttributeInstance->redactIfUserHasAnyRoles )>0 && count( array_intersect( $redactAttributeInstance->redactIfUserHasAnyRoles, $authUser->roles ) )>0 ) {
						unset( $export[ $property->getName() ] );
					}
					elseif( count( $redactAttributeInstance->redactIfUserHasAllRoles )>0 && count( array_diff( $redactAttributeInstance->redactIfUserHasAllRoles, $authUser->roles ) )===0 ) {
						unset( $export[ $property->getName() ] );
					}
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoService', 'Generate attribute data failed: ' . $e->getMessage(), $e->getTrace() );
		}
		return $export;
	}


	protected function _afterJsonDeserialize(): void {
		//reset meta fields
		$this->_meta = new _meta( get_called_class() );

		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$reflectionClass = new \ReflectionClass( $calledClassFqn );

			foreach( $reflectionClass->getProperties() as $property ) {

				//meta
				$classIncludeMetaAttributes = $reflectionClass->getAttributes( includeMeta::class );
				foreach( $classIncludeMetaAttributes as $classIncludeMetaAttribute ) {
					$includeMetaAttributeInstance = $classIncludeMetaAttribute->newInstance();
					if( !$includeMetaAttributeInstance->includeMeta ) {
						unset( $this->_meta );
					}
				}

				//get all attributes for this property
				$propertyAttributes = $property->getAttributes( redact::class );
				foreach( $propertyAttributes as $propertyAttribute ) {
					$authUser                = authUser::getInstance();
					$redactAttributeInstance = $propertyAttribute->newInstance();
					if( count( $redactAttributeInstance->redactIfUserHasAnyRoles )===0 && count( $redactAttributeInstance->redactIfUserHasAllRoles )===0 ) {
						$key = $property->getName();
						unset( $this->$key );
					}
					elseif( count( $redactAttributeInstance->redactIfUserHasAnyRoles )>0 && count( array_intersect( $redactAttributeInstance->redactIfUserHasAnyRoles, $authUser->roles ) )>0 ) {
						$key = $property->getName();
						unset( $this->$key );
					}
					elseif( count( $redactAttributeInstance->redactIfUserHasAllRoles )>0 && count( array_diff( $redactAttributeInstance->redactIfUserHasAllRoles, $authUser->roles ) )===0 ) {
						$key = $property->getName();
						unset( $this->$key );
					}
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoService', 'Generate attribute data failed: ' . $e->getMessage(), $e->getTrace() );
		}
	}


	public static function getTypeMap(): typeMap {
		return typeMapFactory::get( get_called_class() );
	}


	#[Deprecated]
	public static function _typeMap(): typeMap {
		return self::getTypeMap();
	}


	#[ArrayShape( [
		'root'       => "string",
		'fieldPaths' => "string[]"
	] )]
	public static function getBsonOptionsTypeMap(): array {
		return typeMapFactory::get( get_called_class() )->toArray();
	}


	#[Deprecated]
	public static function _getTypeMap(): array {
		return self::getBsonOptionsTypeMap();
	}


	/**
	 * Called by Mongo while inserting into the DB
	 */
	public function bsonSerialize(): array|\stdClass {
		if( method_exists( $this, '_beforeBsonSerialize' ) ) {
			$this->_beforeBsonSerialize();
		}

		$save = [];

		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to serialize data to bson for ' . $calledClassFqn, 500, $e );
		}

		//get properties of the class and add them to the export
		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {

			//if property is not meant to be serialized, exclude it
			$attributes = $rProperty->getAttributes( excludeBsonSerialize::class );
			if( count( $attributes )>0 ) {
				continue;
			}

			//if property has been removed from the object, do not touch it
			if( !$rProperty->isInitialized( $this ) ) {
				continue;
			}

			$propertyName = $rProperty->getName();

			$rPropertyType     = null;
			$rPropertyTypeName = '';

			if( $rProperty->hasType() ) {
				$rPropertyType = $rProperty->getType();

				if( !( $rPropertyType instanceof \ReflectionUnionType ) ) {
					$rPropertyTypeName = $rPropertyType->getName();
				}
			}

			//if the property is an array, check if the doc comment defines the type
			$propertyIsTypedArray = false;
			if( $rPropertyTypeName=='array' ) {
				$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $arrayType!='array' ) {
					$propertyIsTypedArray = true;
				}
			}

			//load the data from json into the instance of our class
			if( $propertyIsTypedArray ) {
				if( !isset( $save[ $propertyName ] ) ) {
					$save[ $propertyName ] = $rProperty->getDefaultValue();
				}
				foreach( $rProperty->getValue( $this ) as $key => $value ) {
					$save[ $propertyName ][ $key ] = $this->bsonSerializeDataItem( $rProperty, $value );
				}
			}
			else {
				$value                 = $rProperty->getValue( $this );
				$save[ $propertyName ] = $this->bsonSerializeDataItem( $rProperty, $value );
			}
		}

		return $save;
	}


	/**
	 * Called by Mongo while selecting the object from the DB
	 *
	 * @param array $data Properties within the BSON document
	 */
	public function bsonUnserialize( array $data ): void {
		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to unserialize bson data for ' . $calledClassFqn, 500, $e );
		}

		$this->_meta = new _meta( $calledClassFqn );

		//get properties of the class and set their values to the data provided from the database
		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			$propertyName = $rProperty->getName();

			//if property is not meant to be unserialized, exclude it
			$attributes = $rProperty->getAttributes( excludeBsonUnserialize::class );
			if( count( $attributes )>0 ) {
				continue;
			}

			$propertyType     = null;
			$propertyTypeName = '';

			if( $rProperty->hasType() ) {
				$propertyType = $rProperty->getType();

				if( !( $propertyType instanceof \ReflectionUnionType ) ) {
					$propertyTypeName = $propertyType->getName();
				}
			}

			//if the property is an array, check if the doc comment defines the type
			$propertyIsTypedArray = false;
			if( $propertyTypeName=='array' ) {
				$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $arrayType!='array' && $arrayType!='string' && $arrayType!='float' && $arrayType!='int' ) {
					$propertyIsTypedArray = true;
				}
			}

			if( $propertyIsTypedArray ) {
				if( !isset( $data[ $propertyName ] ) ) {
					$data[ $propertyName ] = $rProperty->getDefaultValue();
				}
				foreach( $data[ $propertyName ] as $key => $value ) {
					$this->$propertyName[ $key ] = $this->bsonUnserializeDataItem( $rProperty, $propertyType, $arrayType, $value );
				}
			}
			else {
				if( !array_key_exists( $propertyName, $data ) ) {
					$value = null;
				}
				else {
					$value = $data[ $propertyName ];
				}

				//set the class property = the parsed value from the database
				try {
					$this->$propertyName = $this->bsonUnserializeDataItem( $rProperty, $propertyType, $propertyTypeName, $value );
				}
				catch( \Exception|\TypeError $e ) {
					error_log( $e );
				}
			}
		}

		//if this is a text search that has provided the score of the result via the _score field
		if( isset( $data[ '_score' ] ) ) {
			$this->_meta->score = round( $data[ '_score' ], 2 );
		}

		if( method_exists( $this, '_afterBsonUnserialize' ) ) {
			$this->_afterBsonUnserialize( $data );
		}
	}


	/**
	 * @param \ReflectionProperty $rProperty
	 * @param mixed               $value
	 *
	 * @return mixed
	 */
	private function bsonSerializeDataItem( \ReflectionProperty $rProperty, mixed $value ): mixed {
		if( $value instanceof \DateTimeInterface ) {
			return new \MongoDB\BSON\UTCDateTime( $value );
		}

		return $value;
	}


	/**
	 * @param \ReflectionProperty   $rProperty
	 * @param                       $propertyType
	 * @param                       $propertyTypeName
	 * @param mixed                 $value
	 *
	 * @return mixed
	 */
	private function bsonUnserializeDataItem( \ReflectionProperty $rProperty, $propertyType, $propertyTypeName, mixed $value ): mixed {
		//$data[$propertyName] was not in the database result
		if( $value===null ) {
			if( $propertyType!==null && !$propertyType->allowsNull() ) {
				//attempt to instantiate special types
				try {
					$rPropertyClass       = new \ReflectionClass( $propertyTypeName );
					$instantiateArguments = [];
					if( substr( $propertyTypeName, -5 )=='_meta' ) {
						$instantiateArguments[] = get_called_class();
					}

					return $rPropertyClass->newInstance( ...$instantiateArguments );
				}
					//regular non class types
				catch( \ReflectionException $e ) {
					return $rProperty->getDefaultValue();
				}
			}

			return null;
		}

		//$data[ $propertyName ] exists and has value
		else {
			if( $propertyTypeName==='array' && $value instanceof \stdClass ) {
				return (array)$value;
			}
			if( $propertyTypeName==='array' && $value instanceof \MongoDB\Model\BSONArray ) {
				return (array)$value;
			}
			else {
				try {
					$rPropertyClass = new \ReflectionClass( $propertyTypeName );

					if( $rPropertyClass->implementsInterface( \DateTimeInterface::class ) ) {
						if( $value instanceof \MongoDB\BSON\UTCDateTime ) {
							return \DateTimeImmutable::createFromMutable( $value->toDateTime() )->setTimezone( new \DateTimeZone( "America/New_York" ) );
						}
						elseif( is_string( $value ) ) {
							try {
								return new \DateTimeImmutable( $value );
							}
							catch( \Exception $e ) {
								log::warning( 'MongoService', 'Invalid date is stored in database. ' . $e->getMessage(), $e->getTrace() );

								return new \DateTimeImmutable();
							}
						}
					}
				}
					//regular non class types
				catch( \ReflectionException $e ) {
				}
			}

			return $value;
		}
	}


	public static function mongoFieldsExistsQuery( $fieldPrefix = '' ): array {
		$query = [];

		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to load ' . $calledClassFqn . ' to generate typemap', 500, $e );
		}

		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			//skip type mapping this if property is
			//  - excluded from serialization
			//  - starts with _
			$excludeBsonSerializeAttributes = $rProperty->getAttributes( excludeBsonSerialize::class );
			if( count( $excludeBsonSerializeAttributes )>0 || str_starts_with( $rProperty->getName(), '_' ) ) {
				continue;
			}

			//get property type
			$rPropertyName = $rProperty->getName();
			$rPropertyType = $rProperty->getType();
			$typeName      = '';
			if( !( $rPropertyType instanceof \ReflectionUnionType ) ) {
				$typeName = $rPropertyType->getName();
			}
			$propertyIsArray = false;

			//add base field to the search
			$query[] = [ $rPropertyName => [ '$exists' => false ] ];

			//handle typed arrays
			if( $typeName=='array' ) {
				//get type  from @var doc block
				$typeName        = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				$propertyIsArray = true;
			}

			//TODO: is this the best way to capture \app\models
			if( ( str_starts_with( $typeName, 'app' ) || str_starts_with( $typeName, '\app' ) ) && !$rPropertyType->allowsNull() ) {
				//create mongo field path key
				$baseFieldPathKey = ( $fieldPrefix=='' ? '' : $fieldPrefix . '.' ) . $rPropertyName;

				//add the field paths for the property type so that we get a full chain of types
				try {
					$rPropertyClass = new \ReflectionClass( $typeName );
					if( $rPropertyClass->isSubclassOf( embeddable::class ) ) {
						$instance = $rPropertyClass->newInstanceWithoutConstructor();
						/** @var \gcgov\framework\services\mongodb\typeMap $propertyTypeMap */
						$fieldExistsQuery = $rPropertyClass->getMethod( 'mongoFieldsExistsQuery' )->invoke( $instance, $rPropertyName );
						$propertyQuery    = [ '$or' => [] ];
						foreach( $fieldExistsQuery as $or ) {
							foreach( $or as $subFieldPathKey => $exists ) {
								$propertyQuery[ '$or' ][][ $rPropertyName . '.' . $subFieldPathKey ] = $exists;
							}
						}
						if( $propertyIsArray ) {
							$propertyQuery[ $baseFieldPathKey ] = [ '$gt' => [ '$size' => 0 ] ];
							$query[]                            = $propertyQuery;
						}
						else {
							$query = array_merge( $query, $propertyQuery[ '$or' ] );
						}
					}
				}
				catch( \ReflectionException $e ) {
					throw new databaseException( 'Failed to generate query for ' . $typeName, 500, $e );
				}
			}
		}

		return $query;
	}

}