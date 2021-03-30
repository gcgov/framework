<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class excludeSerialize {

	public function __construct() {
	}

}