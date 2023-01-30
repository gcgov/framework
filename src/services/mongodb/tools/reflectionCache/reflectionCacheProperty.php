<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\typeHelpers;

final class reflectionCacheProperty {

	public \ReflectionProperty                            $reflectionProperty;
	public string                                         $propertyName         = '';
	public bool                                           $propertyHasType      = false;
	public null|\ReflectionNamedType|\ReflectionUnionType $propertyType         = null;
	public string                                         $propertyTypeName     = '';
	public string                                         $propertyTypeNameFQN  = '';
	public bool                                           $hasDefaultValue      = false;
	public mixed                                          $defaultValue         = null;
	public bool                                           $propertyIsArray      = false;
	public bool                                           $propertyIsTypedArray = false;

	use reflectionCacheAttributeTrait;

	public function __construct( \ReflectionProperty $reflectionProperty ) {

		$this->reflectionProperty = $reflectionProperty;

		$this->propertyName = $reflectionProperty->getName();

		$this->defineAttributes( $reflectionProperty->getAttributes() );

		$this->defineTypeInfo( $reflectionProperty );

		$this->hasDefaultValue = $reflectionProperty->hasDefaultValue();
		if( $this->hasDefaultValue ) {
			$this->defaultValue = $reflectionProperty->getDefaultValue();
		}
	}


	private function defineTypeInfo( \ReflectionProperty $reflectionProperty ): void {

		$this->propertyHasType = $reflectionProperty->hasType();

		//if the property does not have a type defined, exit
		if( !$this->propertyHasType ) {
			return;
		}

		//get property type reflection
		$this->propertyType = $reflectionProperty->getType();

		//union types not checked - could potentially be improved in the future
		if( $this->propertyType instanceof \ReflectionUnionType ) {
			return;
		}

		//get name of type
		$this->propertyTypeName    = $this->propertyType->getName();

		//handle typed arrays from the type defined in PHPDoc @var
		if( $this->propertyTypeName=='array' ) {
			$this->propertyIsArray = true;
			$arrayType             = typeHelpers::getVarTypeFromDocComment( $reflectionProperty->getDocComment() );
			if( $arrayType!=='array' ) {
				$this->propertyIsTypedArray = true;
				$this->propertyTypeName     = $arrayType;
			}
		}

		$this->propertyTypeNameFQN = typeHelpers::classNameToFqn( $this->propertyTypeName );

	}

}