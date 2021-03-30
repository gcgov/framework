<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class excludeDeserialize {

	public function __construct() {
	}

}