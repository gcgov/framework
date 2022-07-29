<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_CLASS )]
class includeMeta {

	public bool $includeMeta = true;

	public function __construct( bool $includeMeta = true ) {
		$this->includeMeta = $includeMeta;
	}

}