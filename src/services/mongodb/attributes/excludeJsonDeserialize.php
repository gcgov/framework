<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class excludeJsonDeserialize extends \andrewsauder\jsonDeserialize\attributes\excludeJsonDeserialize {

	public function __construct() {
		parent::__construct();
	}

}