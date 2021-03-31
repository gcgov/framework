<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class foreignKey {

	public array $collections = [];

	public function __construct( string|array $referenceCollections ) {

		if(is_string($referenceCollections)) {
			$this->collections = [$referenceCollections];
		}
		else {
			$this->collections = $referenceCollections;
		}
	}

}