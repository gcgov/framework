<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\typeHelpers;

final class reflectionCacheProperty {

	use reflectionCacheAttributeTrait;

	public string $propertyName = '';

	public bool                                           $propertyHasType     = false;
	public null|\ReflectionNamedType|\ReflectionUnionType $propertyType        = null;
	public string                                         $propertyTypeName    = '';
	public string                                         $propertyTypeNameFQN = '';

	public bool $propertyIsArray      = false;
	public bool $propertyIsTypedArray = false;

	public bool  $hasDefaultValue = false;
	public mixed $defaultValue    = null;
	public bool $allowsNulls    = false;

	// hot flags
	public bool $excludeBsonSerialize   = false;
	public bool $excludeBsonUnserialize = false;
	public bool $excludeJsonDeserialize = false;

	// quick check for cloning
	public bool $propertyTypeIsCloneable = false;

	// lazily used ReflectionProperty (for isInitialized/get/set)
	public \ReflectionProperty $reflectionProperty;
	public bool                $propertyTypeIsEnum = false;

	public bool $propertyTypeEnumIsBacked = false;

	// type checks
	public bool $propertyTypeImplementsStringable = false;

	public bool $propertyTypeImplementsDateTimeInterface = false;

	public bool $propertyTypeImplementsPersistable = false;

	// embeddable types

	public bool $propertyTypeIsEmbeddable = false;


	/**
	 * Build from ReflectionProperty
	 *
	 * @param array $typeFeatureFlags [
	 *   'isEnum'=>bool,
	 *   'enumIsBacked'=>bool,
	 *   'isDateTime'=>bool,
	 *   'isPersistable'=>bool,
	 *   'isEmbeddable'=>bool
	 * ]
	 */
	public static function fromReflection( \ReflectionProperty $rp,
	                                       array $classDefaultProps,
	                                       array $attrSnapshot,
	                                       bool $typeIsCloneable,
	                                       array $typeFeatureFlags = [] ): self {
		$self                     = new self();
		$self->propertyName       = $rp->getName();
		$self->reflectionProperty = $rp;

		$self->setAttributeSnapshot( $attrSnapshot );

		$self->propertyHasType  = $rp->hasType();
		$self->propertyType     = $rp->getType();
		$self->propertyTypeName = '';
		if( $self->propertyHasType ) {
			$t = $self->propertyType;
			if( $t instanceof \ReflectionNamedType ) {
				$self->propertyTypeName = $t->getName();
			}
		}

		// parse @var for typed arrays
		$docType = typeHelpers::getVarTypeFromDocComment( $rp->getDocComment() ?: '' );
		if( $self->propertyHasType ) {
			if( $self->propertyTypeName==='array' ) {
				$self->propertyIsArray = true;
				if( $docType && $docType!=='array' ) {
					$self->propertyIsTypedArray = true;
					$self->propertyTypeName     = $docType;
				}
			}
		}
		elseif( $docType==='array' ) {
			$self->propertyIsArray = true;
		}

		$self->propertyTypeNameFQN = typeHelpers::classNameToFqn( $self->propertyTypeName ?: '' );

		// defaults
		$self->hasDefaultValue = array_key_exists( $self->propertyName, $classDefaultProps );
		$self->defaultValue    = $self->hasDefaultValue ? $classDefaultProps[ $self->propertyName ] : null;
		$self->allowsNulls    = $self->propertyType!=null ? $self->propertyType->allowsNull() : true;

		// hot flags (attributes present?)
		$self->excludeBsonSerialize   = isset( $attrSnapshot[ \gcgov\framework\services\mongodb\attributes\excludeBsonSerialize::class ] );
		$self->excludeBsonUnserialize = isset( $attrSnapshot[ \gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize::class ] );
		$self->excludeJsonDeserialize = isset( $attrSnapshot[ \gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize::class ] );

		$self->propertyTypeIsCloneable = $typeIsCloneable;

		// NEW: hydrate type feature flags (defaults to false if missing)
		$self->propertyTypeIsEnum                      = (bool)($typeFeatureFlags['isEnum']        ?? false);
		$self->propertyTypeEnumIsBacked                = (bool)($typeFeatureFlags['enumIsBacked']  ?? false);
		$self->propertyTypeImplementsDateTimeInterface = (bool)($typeFeatureFlags['isDateTime']    ?? false);
		$self->propertyTypeImplementsPersistable       = (bool)($typeFeatureFlags['isPersistable'] ?? false);
		$self->propertyTypeIsEmbeddable                = (bool)($typeFeatureFlags['isEmbeddable']  ?? false);

		return $self;
	}

}
