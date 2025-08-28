<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\models\authUser;
use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
use gcgov\framework\services\mongodb\attributes\includeMeta;
use gcgov\framework\services\mongodb\attributes\redact;
use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\models\_meta;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\tools\reflectionCache;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty;
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
		try {
			$rc = \gcgov\framework\services\mongodb\tools\reflectionCache::getReflectionClass( get_called_class() );
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\services\mongodb\exceptions\databaseException( 'Failed to clone ' . get_called_class(), 500, $e );
		}

		foreach( $rc->properties as $p ) {
			$name = $p->propertyName;
			if( !isset( $this->{$name} ) ) {
				continue;
			}

			// typed array: clone each object element if cloneable
			if( $p->propertyIsTypedArray ) {
				foreach( $this->{$name} as $i => $v ) {
					if( \is_object( $v ) && $p->propertyTypeIsCloneable ) {
						$this->{$name}[ $i ] = clone $v;
					}
				}
				continue;
			}

			// single object
			if( \is_object( $this->{$name} ) && $p->propertyTypeIsCloneable ) {
				$this->{$name} = clone $this->{$name};
			}
		}
	}


	protected function _beforeJsonSerialize(): void {
		if( !isset( $this->_meta ) ) {
			$this->_meta = new _meta( get_called_class() );
		}
	}


	protected function _afterJsonSerialize( array $export ): array {
		try {
			$rc = reflectionCache::getReflectionClass( get_called_class() );

			// property-level redaction
			$redactByProp = $rc->getAttributeInstancesByPropertyName( redact::class );
			if( !empty( $redactByProp ) ) {
				$authUser = authUser::getInstance();

				foreach( $redactByProp as $propName => $attr ) {
					// only consider properties that are included in the export
					if( !array_key_exists( $propName, $export ) ) {
						continue;
					}

					// SHOW constraints (hide if user does NOT meet)
					if( !empty( $attr->showIfUserHasAnyRoles ?? [] ) &&
						count( array_intersect( $attr->showIfUserHasAnyRoles, $authUser->roles ) )===0 ) {
						unset( $export[ $propName ] );
						continue;
					}
					if( !empty( $attr->showIfUserHasAllRoles ?? [] ) &&
						count( array_diff( $attr->showIfUserHasAllRoles, $authUser->roles ) )>0 ) {
						unset( $export[ $propName ] );
						continue;
					}

					// REDACT constraints (hide if user DOES meet)
					if( !empty( $attr->redactIfUserHasAnyRoles ?? [] ) &&
						count( array_intersect( $attr->redactIfUserHasAnyRoles, $authUser->roles ) )>0 ) {
						unset( $export[ $propName ] );
						continue;
					}
					if( !empty( $attr->redactIfUserHasAllRoles ?? [] ) &&
						count( array_diff( $attr->redactIfUserHasAllRoles, $authUser->roles ) )===0 ) {
						unset( $export[ $propName ] );
						continue;
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
		// reset meta
		$this->_meta = new _meta( get_called_class() );

		try {
			$rc = reflectionCache::getReflectionClass( get_called_class() );

			// includeMeta handling
			if( $rc->hasAttribute( includeMeta::class ) ) {
				/** @var includeMeta $includeMeta */
				$includeMeta = $rc->getAttributeInstance( includeMeta::class );
				if( !$includeMeta->includeMeta ) {
					unset( $this->_meta );
				}
			}

			// property-level redaction
			$redactByProp = $rc->getAttributeInstancesByPropertyName( redact::class );
			if( !empty( $redactByProp ) ) {
				$authUser  = authUser::getInstance();
				$userRoles = $authUser->roles ?? [];

				foreach( $redactByProp as $propName => $attr ) {
					// If property isn't set there is nothing to redact
					if( !isset( $this->$propName ) ) {
						continue;
					}

					// SHOW constraints (remove if user does NOT meet)
					if( !empty( $attr->showIfUserHasAnyRoles ?? [] ) &&
						count( array_intersect( $attr->showIfUserHasAnyRoles, $userRoles ) )===0 ) {
						unset( $this->$propName );
						continue;
					}
					if( !empty( $attr->showIfUserHasAllRoles ?? [] ) &&
						count( array_diff( $attr->showIfUserHasAllRoles, $userRoles ) )>0 ) {
						unset( $this->$propName );
						continue;
					}

					// REDACT constraints (remove if user DOES meet)
					if( !empty( $attr->redactIfUserHasAnyRoles ?? [] ) &&
						count( array_intersect( $attr->redactIfUserHasAnyRoles, $userRoles ) )>0 ) {
						unset( $this->$propName );
						continue;
					}
					if( !empty( $attr->redactIfUserHasAllRoles ?? [] ) &&
						count( array_diff( $attr->redactIfUserHasAllRoles, $userRoles ) )===0 ) {
						unset( $this->$propName );
						continue;
					}
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoService', 'Generate attribute data failed: ' . $e->getMessage(), $e->getTrace() );
		}
	}


	public static function getTypeMap( typeMapType $type = typeMapType::serialize ): typeMap {
		return typeMapFactory::get( get_called_class(), $type );
	}


	#[Deprecated]
	public static function _typeMap(): typeMap {
		return self::getTypeMap();
	}


	#[ArrayShape( [
		'root'       => "string",
		'fieldPaths' => "string[]"
	] )]
	public static function getBsonOptionsTypeMap( typeMapType $type = typeMapType::serialize ): array {
		return typeMapFactory::get( get_called_class(), $type )->toArray();
	}


	#[Deprecated]
	/** use static::getBsonOptionsTypeMap instead */
	public static function _getTypeMap(): array {
		return self::getBsonOptionsTypeMap();
	}


	/**
	 * Called by Mongo while inserting into the DB
	 */
	public function bsonSerialize(): array|\stdClass {
		return $this->doBsonSerialize();
	}


	public function doBsonSerialize( bool $deep = false, bool $dateAsISOString = false ): array|\stdClass {
		if( method_exists( $this, '_beforeBsonSerialize' ) ) {
			$this->_beforeBsonSerialize();
		}

		$save = [];

		try {
			$rc = \gcgov\framework\services\mongodb\tools\reflectionCache::getReflectionClass( get_called_class() );

			foreach( $rc->properties as $p ) {
				if( $p->excludeBsonSerialize ) {
					continue;
				}

				// typed props require init check via ReflectionProperty
				if( !$p->reflectionProperty->isInitialized( $this ) ) {
					continue;
				}

				if( $p->propertyIsTypedArray ) {
					$val = $p->reflectionProperty->getValue( $this );
					if( !\is_array( $val ) ) {
						continue;
					}
					$out = [];
					foreach( $val as $k => $v ) {
						$out[ $k ] = $this->bsonSerializeDataItem( $v, $deep, $dateAsISOString );
					}
					$save[ $p->propertyName ] = $out;
				}
				else {
					$val                      = $p->reflectionProperty->getValue( $this );
					$save[ $p->propertyName ] = $this->bsonSerializeDataItem( $val,  $deep, $dateAsISOString );
				}
			}
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\services\mongodb\exceptions\databaseException( 'BSON serialize failed for ' . get_called_class(), 500, $e );
		}

		return $save;
	}


	/**
	 * Called by Mongo while selecting the object from the DB
	 *
	 * @param array $data Properties within the BSON document
	 */
	public function bsonUnserialize( array $data ): void {
		try {
			$rc = \gcgov\framework\services\mongodb\tools\reflectionCache::getReflectionClass( get_called_class() );
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\services\mongodb\exceptions\databaseException( 'BSON unserialize failed for ' . get_called_class(), 500, $e );
		}

		foreach( $rc->properties as $p ) {
			$name = $p->propertyName;

			if( $p->excludeBsonUnserialize ) {
				continue;
			}

			// not present in DB result
			if( !array_key_exists( $name, $data ) ) {
				// preserve default if any
				if( $p->hasDefaultValue ) {
					$this->{$name} = $p->defaultValue;
				}
				continue;
			}

			$raw = $data[ $name ];

			// typed array of objects
			if( $p->propertyIsTypedArray && \is_array( $raw ) ) {
				$dest = [];
				foreach( $raw as $k => $v ) {
					$dest[ $k ] = $this->bsonUnserializeDataItem( $p, $v );
				}
				$this->{$name} = $dest;
				continue;
			}

			// single value
			$this->{$name} = $this->bsonUnserializeDataItem( $p, $raw );
		}

		//ensure _meta is present
		$this->_meta = new \gcgov\framework\services\mongodb\models\_meta( \get_called_class() );

		//if this is a text search that has provided the score of the result via the _score field
		if( isset( $data[ '_score' ] ) ) {
			$setScore = true;
			if( $rc->hasAttribute( includeMeta::class ) ) {
				/** @var includeMeta $includeMeta */
				$includeMeta = $rc->getAttributeInstance( includeMeta::class );
				if( !$includeMeta->includeMeta ) {
					$setScore = false;
				}
			}
			if( $setScore ) {
				$this->_meta->score = round( $data[ '_score' ], 2 );
			}
		}

		if( method_exists( $this, '_afterBsonUnserialize' ) ) {
			$this->_afterBsonUnserialize( $data );
		}
	}


	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	private function bsonSerializeDataItem( mixed $value, bool $deep = false, bool $dateAsISOString = false ): mixed {
		if( !$dateAsISOString && $value instanceof \DateTimeInterface ) {
			return new \MongoDB\BSON\UTCDateTime( $value );
		}
		elseif( $dateAsISOString && $value instanceof \DateTimeInterface ) {
			return $value->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
		}
		elseif( $deep===true && $value instanceof embeddable ) {
			return $value->doBsonSerialize( true, $dateAsISOString );
		}
		elseif( $deep===true && $value instanceof \MongoDB\BSON\Persistable ) {
			return $value->bsonSerialize();
		}

		return $value;
	}


	/**
	 * @param \gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty $p
	 * @param mixed                                                                           $value
	 *
	 * @return mixed
	 */
	private function bsonUnserializeDataItem( reflectionCacheProperty $p, mixed $value ): mixed {
		// If DB didn’t provide a value
		if( $value===null ) {
			// If the declared type is non-nullable, try to construct special defaults
			$propertyType = $p->propertyType; // ReflectionType|null
			if( $propertyType!==null && !$propertyType->allowsNull() ) {
				$typeName = $p->propertyTypeName;
				if( $typeName!=='' ) {
					// _meta special case
					if( \str_ends_with( $typeName, '_meta' ) ) {
						return new \gcgov\framework\services\mongodb\models\_meta( \get_called_class() );
					}

					if ($p->propertyTypeIsEnum) {
						if ($p->propertyTypeEnumIsBacked) {
							return $p->propertyTypeNameFQN::from($value);
						}
						$const = $p->propertyTypeNameFQN.'::'.$value;
						if (\defined($const)) {
							return \constant($const);
						}
					}
				}
			}
			return null; // leave null
		}

		// Handle DateTime targets efficiently (no ReflectionClass instantiation)
		if ($p->propertyTypeImplementsDateTimeInterface) {
			if( $value instanceof \MongoDB\BSON\UTCDateTime ) {
				return \DateTimeImmutable::createFromMutable( $value->toDateTime() )
				                         ->setTimezone( new \DateTimeZone( 'America/New_York' ) );
			}
			if( \is_string( $value ) ) {
				try {
					return new \DateTimeImmutable( $value );
				}
				catch( \Exception $e ) {
					\gcgov\framework\services\mongodb\tools\log::warning(
						'MongoUnserialize',
						'Invalid date is stored in database. ' . $e->getMessage()
					);
					return new \DateTimeImmutable();
				}
			}
			// Already a DateTime or unsupported format -> pass through
			return $value;
		}

		// Enums (avoid ReflectionEnum on hot path)
		if ($p->propertyTypeIsEnum) {
			if ($p->propertyTypeEnumIsBacked) {
				/** ::from is safe */
				return $p->propertyTypeNameFQN::from($value);
			}
			$const = $p->propertyTypeNameFQN.'::'.$value;
			if (\defined($const)) {
				return \constant( $const );
			}
		}

		// Embedded document / Persistable types
		if (\is_array($value) && $p->propertyTypeNameFQN !== '' && \class_exists($p->propertyTypeNameFQN)) {
			// gcgov embeddable (your models)
			if (\is_subclass_of($p->propertyTypeNameFQN, \gcgov\framework\services\mongodb\embeddable::class)) {
				$inst = new ($p->propertyTypeNameFQN)();
				$inst->bsonUnserialize($value);
				return $inst;
			}

			// Mongo Persistable (userland types)
			if (\is_subclass_of($p->propertyTypeNameFQN, \MongoDB\BSON\Persistable::class)) {
				$inst = new ($p->propertyTypeNameFQN)();
				// If class implements Persistable, it must define bsonUnserialize; call it if present
				if (\method_exists($inst, 'bsonUnserialize')) {
					$inst->bsonUnserialize($value);
				}
				return $inst;
			}
		}

		if( $p->propertyTypeNameFQN==='MongoDB\BSON\ObjectId' && is_string($value) ) {
			return new \MongoDB\BSON\ObjectId($value);
		}

		if( $p->propertyIsArray && $value instanceof \stdClass ) {
			return (array)$value;
		}

		if( $p->propertyIsArray && $value instanceof \MongoDB\Model\BSONDocument ) {
			return (array)$value;
		}

		if( $p->propertyIsArray && $value instanceof \MongoDB\Model\BSONArray ) {
			return (array)$value;
		}

		return $value;
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
			if( count( $excludeBsonSerializeAttributes )>0 ) {
				continue;
			}

			//get property type
			$rPropertyName = $rProperty->getName();
			$rPropertyType = $rProperty->getType();
			$typeName      = '';
			if( $rPropertyType!==null && method_exists( $rPropertyType, 'getName' ) ) {
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


	/**
	 * Run Symfony validation against object's #[Assert\...] attributes. Method updates _meta->fields->error and _meta->fields->errorMessages[] with violations and returns the constraint violations to the caller
	 *
	 * @param string[]|null $validationGroups
	 * @param bool          $includeDefaultGroup
	 *
	 * @return \Symfony\Component\Validator\ConstraintViolationListInterface
	 */
	public function updateValidationState( ?array $validationGroups = null, bool $includeDefaultGroup = true ): \Symfony\Component\Validator\ConstraintViolationListInterface {
		$validator = \Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

		if( $validationGroups===null && method_exists( $this, '_defineValidationGroups' ) ) {
			$validationGroups = $this->_defineValidationGroups();
			if( !is_array( $validationGroups ) ) {
				throw new \LogicException( '_defineValidationGroups must return an array of strings' );
			}
		}
		if( !is_array( $validationGroups ) ) {
			$validationGroups = [];
		}

		if( count( $validationGroups )>0 ) {
			if( !in_array( 'Default', $validationGroups ) && $includeDefaultGroup ) {
				$validationGroups[] = 'Default';
			}
			$violations = $validator->validate( value: $this, groups: $validationGroups );
		}
		else {
			$violations = $validator->validate( value: $this );
		}

		if( count( $violations )>0 ) {
			$propertyAccessor = \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor();

			foreach( $violations as $violation ) {
				$propertyPath = $violation->getPropertyPath();
				if( str_contains( $propertyPath, '.' ) ) {
					$parentObjPath = substr( $propertyPath, 0, strrpos( $propertyPath, '.' ) );
					$fieldPath     = substr( $propertyPath, strrpos( $propertyPath, '.' ) + 1 );
					$metaPath      = $parentObjPath . '._meta.fields[' . $fieldPath . ']';
				}
				else {
					$fieldPath = $propertyPath;
					$metaPath  = '_meta.fields[' . $fieldPath . ']';
				}

				/** @var \gcgov\framework\services\mongodb\models\_meta\uiField $metaVal */
				$uiField = $propertyAccessor->getValue( $this, $metaPath );

				if( $uiField instanceof \gcgov\framework\services\mongodb\models\_meta\uiField ) {
					$uiField->error           = true;
					$uiField->errorMessages[] = $violation->getMessage();//.' - field '.$propertyPath.' meta '.$metaPath;
				}
				else {
					log::error( 'MongoService', 'Validation violation not recorded in _meta for ' . get_called_class() . ' property path ' . $propertyPath . '. The meta field path (computed to be ' . $metaPath . ') did was not an instance of a \gcgov\framework\services\mongodb\models\_meta\uiField' );
				}

			}

		}

		return $violations;

	}

}
