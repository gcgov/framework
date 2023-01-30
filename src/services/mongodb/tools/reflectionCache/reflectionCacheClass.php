<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\exceptions\databaseException;

final class reflectionCacheClass {

	public string $classFQN = '';

	public string $name = '';

	public \ReflectionClass $reflectionClass;

	/** @var \ReflectionProperty[] */
	public array $reflectionProperties;

	/** @var \gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty[] */
	public array $properties;

	use reflectionCacheAttributeTrait;

	/** @var \gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty[] Associative array - key=attribute name */
	private array $propertiesWithAttribute = [];

	private array $attributeInstancesByPropertyName = [];


	/**
	 * @param string $classFQN
	 *
	 * @throws \ReflectionException
	 */
	public function __construct( string $classFQN ) {

		$this->classFQN = $classFQN;

		//get class
		$this->reflectionClass = new \ReflectionClass( $this->classFQN );
		$this->name            = $this->reflectionClass->getName();

		$this->defineProperties( $this->reflectionClass->getProperties() );

		$this->defineAttributes( $this->reflectionClass->getAttributes() );
	}


	/**
	 * @param \ReflectionProperty[] $reflectionProperties
	 *
	 * @return void
	 */
	private function defineProperties( array $reflectionProperties ): void {
		$this->reflectionProperties = $reflectionProperties;

		//create parsed properties in $this->properties
		foreach( $this->reflectionProperties as $reflectionProperty ) {
			$property = new reflectionCacheProperty( $reflectionProperty );
			if( !empty( $property->propertyName ) ) {
				$this->properties[ $property->propertyName ] = $property;
			}
		}
	}


	/**
	 * @param $attributeName
	 *
	 * @return \gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty[]
	 */
	public function getPropertiesWithAttribute( $attributeName ): array {
		if( !isset( $this->propertiesWithAttribute[ $attributeName ] ) ) {
			$this->propertiesWithAttribute[ $attributeName ] = [];

			foreach( $this->properties as $property ) {
				if( $property->hasAttribute( $attributeName ) ) {
					$this->propertiesWithAttribute[ $attributeName ][ $property->propertyName ] = $property;
				}
			}
		}

		return $this->propertiesWithAttribute[ $attributeName ];
	}


	/**
	 * @param $attributeName
	 *
	 * @return array
	 */
	public function getAttributeInstancesByPropertyName( $attributeName ): array {
		if( !isset( $this->attributeInstancesByPropertyName[ $attributeName ] ) ) {
			$this->attributeInstancesByPropertyName[ $attributeName ] = [];
			$propertiesWithAttribute                                  = $this->getPropertiesWithAttribute( $attributeName );
			foreach( $propertiesWithAttribute as $property ) {
				$this->attributeInstancesByPropertyName[ $attributeName ][ $property->propertyName ] = $property->getAttributeInstance( $attributeName );
			}

		}

		return $this->attributeInstancesByPropertyName[ $attributeName ];
	}

}