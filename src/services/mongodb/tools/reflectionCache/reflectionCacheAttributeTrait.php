<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\exceptions\databaseException;

trait reflectionCacheAttributeTrait {

	/** @var \ReflectionAttribute[] */
	public array $reflectionAttributes = [];

	private array $_reflectionAttributeInstances = [];


	/**
	 * @param \ReflectionAttribute[] $reflectionAttributes
	 *
	 * @return void
	 */
	private function defineAttributes( array $reflectionAttributes ): void {
		foreach( $reflectionAttributes as $reflectionAttribute ) {
			$this->reflectionAttributes[ $reflectionAttribute->getName() ] = $reflectionAttribute;
		}
	}


	public function hasAttribute( string $name ): bool {
		if( isset( $this->reflectionAttributes[ $name ] ) ) {
			return true;
		}
		return false;
	}


	/**
	 * @param string $name
	 *
	 * @return ?\ReflectionAttribute
	 */
	public function getAttribute( string $name ): ?\ReflectionAttribute {
		if( isset( $this->reflectionAttributes[ $name ] ) ) {
			return $this->reflectionAttributes[ $name ];
		}
		return null;
	}


	/**
	 * @param string $name
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	public function getAttributeInstance( string $name ): mixed {
		if( !isset( $this->_reflectionAttributeInstances[ $name ] ) ) {
			$attribute                                    = $this->getAttribute( $name );
			$this->_reflectionAttributeInstances[ $name ] = $attribute?->newInstance();
		}
		return $this->_reflectionAttributeInstances[ $name ];
	}

}