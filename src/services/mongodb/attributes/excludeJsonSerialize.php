<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class excludeJsonSerialize extends \andrewsauder\jsonDeserialize\attributes\excludeJsonSerialize {

	public function __construct() {
		parent::__construct();
	}

}