<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class foreignKey {

	public string $type = '';
	public string $propertyName = '';

	public function __construct( string $propertyName ) {
		$this->propertyName = $propertyName;
	}

}