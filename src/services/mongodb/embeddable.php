<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\models\appConfig;
use gcgov\framework\services\log;
use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonSerialize;
use gcgov\framework\services\mongodb\attributes\foreignKey;
use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\models\_meta;


abstract class embeddable
	implements
	\MongoDB\BSON\Persistable,
	\JsonSerializable,
	\gcgov\framework\interfaces\jsonDeserialize {

	public _meta $_meta;


	public function __construct() {
		if( config::getEnvironmentConfig()->mongoDatabases) {

		}
		$this->_meta = new _meta( get_called_class() );
	}


	/**
	 * @param  string[]  $chainClass
	 *
	 * @return \gcgov\framework\services\mongodb\typeMap
	 */
	public static function _typeMap( $chainClass=[] ) : typeMap {

		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );
		$chainClass[] = $calledClassFqn;

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to load ' . $calledClassFqn . ' to generate typemap', 500, $e );
		}

		$typeMap = new \gcgov\framework\services\mongodb\typeMap( $calledClassFqn );

		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			//skip type mapping this if property is
			//  - excluded from serialization
			//  - not typed
			//  - starts with _
			$excludeBsonSerializeAttributes = $rProperty->getAttributes( excludeBsonSerialize::class );
			if( count( $excludeBsonSerializeAttributes ) > 0 || !$rProperty->hasType() || substr( $rProperty->getName(), 0, 1 ) === '_' ) {
				continue;
			}

			//get property type
			$rPropertyType = $rProperty->getType();
			$typeName      = $rPropertyType->getName();
			$typeIsArray   = false;

			//handle typed arrays
			if( $typeName == 'array' ) {
				//get type  from @var doc block
				$typeName    = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				$typeIsArray = true;
			}

			//TODO: is this the best way to capture \app\models
			if( substr( $typeName, 0, 3 ) == 'app' || substr( $typeName, 0, 4 ) == '\app' ) {
				//create mongo field path key
				$baseFieldPathKey = $rProperty->getName();
				if( $typeIsArray ) {
					$baseFieldPathKey .= '.$';
				}

				//add foreign field mapping for upserting embedded objects
				$foreignKeyAttributes = $rProperty->getAttributes( foreignKey::class );
				if($foreignKeyAttributes>0) {
					foreach($foreignKeyAttributes as $foreignKeyAttribute) {
						/** @var \gcgov\framework\services\mongodb\attributes\foreignKey $fkAttribute */
						$fkAttribute = $foreignKeyAttribute->newInstance();
						$typeMap->foreignKeyMap[ $baseFieldPathKey ] = $fkAttribute->propertyName;
					}

				}

				//add the primary property type
				$typeMap->fieldPaths[ $baseFieldPathKey ] = typeHelpers::classNameToFqn( $typeName );

				//add the field paths for the property type so that we get a full chain of types
				try {
					$rPropertyClass = new \ReflectionClass( $typeName );
					if( $rPropertyClass->isSubclassOf( embeddable::class ) ) {
						$instance        = $rPropertyClass->newInstanceWithoutConstructor();
						/** @var \gcgov\framework\services\mongodb\typeMap $propertyTypeMap */
						$propertyTypeMap = $rPropertyClass->getMethod( '_typeMap' )->invoke( $instance, $chainClass );
						foreach( $propertyTypeMap->fieldPaths as $subFieldPathKey => $class ) {
							$typeMap->fieldPaths[ $baseFieldPathKey . '.' . $subFieldPathKey ] = typeHelpers::classNameToFqn( $class );
						}
						foreach( $propertyTypeMap->foreignKeyMap as $subFieldPathKey => $fkPropertyName ) {
							$typeMap->foreignKeyMap[ $baseFieldPathKey . '.' . $subFieldPathKey ] = $fkPropertyName;
						}
					}
				}
				catch( \ReflectionException $e ) {
					throw new databaseException( 'Failed to generate type map for ' . $typeName, 500, $e );
				}
			}
		}

		return $typeMap;
	}


	/**
	 * Initialize from outside object
	 *
	 * @param  string|\stdClass  $json
	 *
	 * @return mixed Instance of the called class
	 * @throws \gcgov\framework\exceptions\modelException
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : mixed {
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		//parse the json
		$json = tools\helpers::jsonToObject( $json, 'Malformed ' . $calledClassFqn . ' JSON', 400 );

		if(is_array($json)) {
			$objects = [];
			foreach($json as $stdObject) {
				$objects[] = self::jsonDeserializeObject( $calledClassFqn, $stdObject );
			}
			return $objects;
		}
		else {
			return self::jsonDeserializeObject( $calledClassFqn, $json );
		}


	}

	private static function jsonDeserializeObject( string $calledClassFqn, \stdClass $json ) {
		//load new instance of this class
		try {
			$rClass   = new \ReflectionClass( $calledClassFqn );
			$instance = $rClass->newInstance();
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to load type ' . $calledClassFqn . ' for deserialization', 500, $e );
		}

		//get properties of the class
		$rProperties = $rClass->getProperties();

		//load data from $json into the class $instance
		foreach( $rProperties as $rProperty ) {
			$propertyName = $rProperty->getName();

			//exclude if attribute says to
			$attributes = $rProperty->getAttributes( excludeJsonDeserialize::class );
			if( count( $attributes ) > 0 ) {
				continue;
			}

			if( $propertyName === '_meta' ) {
				continue;
			}

			//if there is not a matching json property, ignore it
			if( !property_exists( $json, $propertyName ) ) {
				continue;
			}

			//get the type of this property
			$rPropertyType = $rProperty->getType();

			//if the property is an array, check if the doc comment defines the type
			$propertyIsTypedArray = false;
			if( $rPropertyType->getName() == 'array' ) {
				$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $arrayType != 'array' ) {
					$propertyIsTypedArray = true;
				}
			}

			//load the data from json into the instance of our class
			if( $propertyIsTypedArray ) {
				$instance->$propertyName = [];
				foreach( $json->$propertyName as $key => $jsonItem ) {
					$instance->$propertyName[ $key ] = self::jsonDeserializeDataItem( $instance, $rProperty, $jsonItem, false );
				}
			}
			else {
				$instance->$propertyName = self::jsonDeserializeDataItem( $instance, $rProperty, $json->$propertyName, $rPropertyType->allowsNull() );
			}
		}

		return $instance;
	}


	/**
	 * @param  mixed                $instance    Instance of the class we are building
	 * @param  \ReflectionProperty  $rProperty   Reflection of the property we are working with
	 * @param  mixed                $jsonValue   Set the property equal to this value - provided from the json object
	 * @param  boolean              $allowsNull  Can the property be set to null
	 *
	 * @return mixed
	 * @throws \gcgov\framework\exceptions\modelException
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	private static function jsonDeserializeDataItem( mixed $instance, \ReflectionProperty $rProperty, mixed $jsonValue, bool $allowsNull ) : mixed {
		$propertyName     = $rProperty->getName();
		$rPropertyType    = $rProperty->getType();
		$propertyTypeName = $rPropertyType->getName();

		//get type of array if specified
		if( $propertyTypeName == 'array' ) {
			//get type  from @var doc block
			$propertyTypeName = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
		}

		//if the property type is a class we try to get reflection information about it and set the value properly, otherwise it does the default types in the catch
		try {
			$rPropertyClass = new \ReflectionClass( $propertyTypeName );
		}
			//regular non class types
		catch( \ReflectionException $e ) {
			if( $jsonValue !== null ) {
				if( $propertyTypeName == 'array' ) {
					return (array) $jsonValue;
				}

				//cast jsonValue to the property type
				$castSuccessfully = settype( $jsonValue, $propertyTypeName );
				if( !$castSuccessfully ) {
					throw new modelException( 'Invalid data type for ' . $propertyName );
				}

				return $jsonValue;
			}

			//return default value
			return $rProperty->getValue( $instance );
		}

		//error messagings
		$errorMessageDataPosition = $instance::class . ' ' . $propertyName;

		//if no value is provided and nulls are not allowed, create a new instance
		if( empty( $jsonValue ) && !$allowsNull ) {
			try {
				return $rPropertyClass->newInstance();
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to instantiate type ' . $propertyTypeName . ' for ' . $errorMessageDataPosition, 500, $e );
			}
		}
		//no value provided, nulls allowed - use the default instantiated value
		elseif( empty( $jsonValue ) && $allowsNull ) {
			//return default value
			return $rProperty->getValue( $instance );
		}

		//object ids
		if( trim( $propertyTypeName, '\\') == trim( \MongoDB\BSON\ObjectId::class, '\\') ) {
			try {
				return $rPropertyClass->newInstance( $jsonValue );
			}
			catch( \MongoDB\Driver\Exception\InvalidArgumentException $e ) {
				throw new \gcgov\framework\exceptions\modelException( 'Invalid id provided for ' . $errorMessageDataPosition, 400, $e );
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to instantiate type ' . $propertyTypeName . ' for ' . $errorMessageDataPosition, 500, $e );
			}
		}

		//implementers of jsonDeserialize
		elseif( $rPropertyClass->implementsInterface( \gcgov\framework\interfaces\jsonDeserialize::class ) ) {
			try {
				$method           = $rPropertyClass->getMethod( 'jsonDeserialize' );
				$tempTypeInstance = $rPropertyClass->newInstanceWithoutConstructor();

				return $method->invoke( $tempTypeInstance, $jsonValue );
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to instantiate type ' . $propertyTypeName . ' for ' . $errorMessageDataPosition, 500, $e );
			}
		}

		//datetimes
		elseif( $rPropertyClass->implementsInterface( \DateTimeInterface::class ) ) {
			try {
				return $rPropertyClass->newInstance( $jsonValue );
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to instantiate type ' . $propertyTypeName . ' for ' . $errorMessageDataPosition, 500, $e );
			}
			catch( \Exception $e ) {
				throw new \gcgov\framework\exceptions\modelException( 'Invalid date time provided for ' . $errorMessageDataPosition, 400, $e );
			}
		}

		//error out if we don't know how to handle - ie. Dates, etc
		throw new \Error( 'Missing type to parse in json deserialize: ' . $propertyTypeName );
	}


	/**
	 * @return array
	 */
	public function jsonSerialize() : array {
		if(method_exists($this, '_beforeJsonSerialize')) {
			$this->_beforeJsonSerialize();
		}

		$export = [];

		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to serialize data to json for ' . $calledClassFqn, 500, $e );
		}

		//get properties of the class and add them to the export
		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			$propertyName = $rProperty->getName();

			//if property is not meant to be serialized, exclude it
			$attributes = $rProperty->getAttributes( excludeJsonSerialize::class );
			if( count( $attributes ) === 0 ) {

				$rPropertyType = $rProperty->getType();

				//if the property is an array, check if the doc comment defines the type
				$propertyIsTypedArray = false;
				if( $rPropertyType->getName() == 'array' ) {
					$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
					if( $arrayType != 'array' ) {
						$propertyIsTypedArray = true;
					}
				}

				//load the data from json into the instance of our class
				if( $propertyIsTypedArray ) {
					$export[ $propertyName ] = [];
					$values                  = $rProperty->getValue( $this );
					foreach( $values as $key => $value ) {
						$export[ $propertyName ][ $key ] = $this->jsonSerializeDataItem( $rProperty, $value );
					}
				}
				else {
					$value                   = $rProperty->getValue( $this );
					$export[ $propertyName ] = $this->jsonSerializeDataItem( $rProperty, $value );
				}
			}
		}

		return $export;
	}


	/**
	 * Called by Mongo while inserting into the DB
	 */
	public function bsonSerialize() : array|\stdClass {
		if(method_exists($this, '_beforeBsonSerialize')) {
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
			$propertyName = $rProperty->getName();

			//if property is not meant to be serialized, exclude it
			$attributes = $rProperty->getAttributes( excludeBsonSerialize::class );
			if( count( $attributes ) > 0 ) {
				continue;
			}

			//ignore properties that start with _
			if( substr( $propertyName, 0, 1 ) === '_' && $propertyName !== '_id' ) {
				continue;
			}

			$rPropertyType = $rProperty->getType();

			//if the property is an array, check if the doc comment defines the type
			$propertyIsTypedArray = false;
			if( $rPropertyType->getName() == 'array' ) {
				$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $arrayType != 'array' ) {
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
	 * @param  array  $data  Properties within the BSON document
	 */
	public function bsonUnserialize( array $data ) {
		//get the called class name
		$calledClassFqn = typeHelpers::classNameToFqn( get_called_class() );

		try {
			$rClass = new \ReflectionClass( $calledClassFqn );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to unserialize bson data for ' . $calledClassFqn, 500, $e );
		}

		//get properties of the class and set their values to the data provided from the database
		$rProperties = $rClass->getProperties();
		foreach( $rProperties as $rProperty ) {
			$propertyName = $rProperty->getName();

			//if property is not meant to be unserialized, exclude it
			$attributes = $rProperty->getAttributes( excludeBsonUnserialize::class );
			if( count( $attributes ) > 0 ) {
				continue;
			}

			$propertyType     = $rProperty->getType();
			$propertyTypeName = $propertyType->getName();

			//if the property is an array, check if the doc comment defines the type
			$propertyIsTypedArray = false;
			if( $propertyTypeName == 'array' ) {
				$arrayType = typeHelpers::getVarTypeFromDocComment( $rProperty->getDocComment() );
				if( $arrayType != 'array' && $arrayType != 'string' && $arrayType != 'float' && $arrayType != 'int' ) {
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
				$this->$propertyName = $this->bsonUnserializeDataItem( $rProperty, $propertyType, $propertyTypeName, $value );
			}
		}

		//if this is a text search that has provided the score of the result via the _score field
		if( isset( $data[ '_score' ] ) ) {
			$this->_meta->score = round( $data[ '_score' ], 2 );
		}

		if(method_exists($this, '_afterBsonUnserialize')) {
			$this->_afterBsonUnserialize( $data );
		}
	}


	/**
	 * @param  \ReflectionProperty  $rProperty
	 * @param  mixed                $value
	 *
	 * @return mixed
	 */
	private function jsonSerializeDataItem( \ReflectionProperty $rProperty, mixed $value ) : mixed {
		if( $value instanceof \MongoDB\BSON\ObjectId ) {
			return (string) $value;
		}
		elseif( $value instanceof \DateTimeInterface ) {
			return $value->format( \DateTime::ATOM );
		}

		return $value;
	}


	/**
	 * @param  \ReflectionProperty  $rProperty
	 * @param  mixed                $value
	 *
	 * @return mixed
	 */
	private function bsonSerializeDataItem( \ReflectionProperty $rProperty, mixed $value ) : mixed {
		if( $value instanceof \DateTimeInterface ) {
			return new \MongoDB\BSON\UTCDateTime( $value );
		}

		return $value;
	}


	/**
	 * @param  \ReflectionProperty  $rProperty
	 * @param                       $propertyType
	 * @param                       $propertyTypeName
	 * @param  mixed                $value
	 *
	 * @return mixed
	 */
	private function bsonUnserializeDataItem( \ReflectionProperty $rProperty, $propertyType, $propertyTypeName, mixed $value ) : mixed {
		//$data[$propertyName] was not in the database result
		if( $value === null ) {
			if( !$propertyType->allowsNull() ) {
				//attempt to instantiate special types
				try {
					$rPropertyClass       = new \ReflectionClass( $propertyTypeName );
					$instantiateArguments = [];
					if( substr( $propertyTypeName, -5 ) == '_meta' ) {
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
			try {
				$rPropertyClass = new \ReflectionClass( $propertyTypeName );

				if( $rPropertyClass->implementsInterface( \DateTimeInterface::class ) ) {
					if( $value instanceof \MongoDB\BSON\UTCDateTime ) {
						return \DateTimeImmutable::createFromMutable( $value->toDateTime() )
						                         ->setTimezone( new \DateTimeZone( "America/New_York" ) );
					}
					elseif( is_string( $value ) ) {
						try {
							return new \DateTimeImmutable( $value );
						}
						catch( \Exception $e ) {
							log::warning( 'MongoService', 'Invalid date is stored in database. '.$e->getMessage(), $e->getTrace() );
							return new \DateTimeImmutable();
						}
					}
				}
			}
				//regular non class types
			catch( \ReflectionException $e ) {
			}

			return $value;
		}
	}

}