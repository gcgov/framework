<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class foreignKey {

	public string $propertyName         = '';
	public array  $embeddedObjectFilter = [];

	/**
	 * @param string $embeddedPropertyName
	 * @param array  $embeddedObjectFilter Foreign key will be limited to only include embedded objects with properties that match these values. Ex: [ 'recurring'=>true ] will only update or insert the embedded object where the embedded object field name recurring is true
	 */
	public function __construct( string $embeddedPropertyName, array $embeddedObjectFilter = [] ) {
		$this->propertyName         = $embeddedPropertyName;
		$this->embeddedObjectFilter = $embeddedObjectFilter;
	}

}