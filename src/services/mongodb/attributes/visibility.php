<?php

namespace gcgov\framework\services\mongodb\attributes;

use Attribute;

#[Attribute( Attribute::TARGET_PROPERTY )]
class visibility {

	public bool $visible = true;

	/** @var string[] */
	public array $visibilityGroups       = [];
	public bool  $valueIsVisibilityGroup = false;


	public function __construct( bool $default = true, array $groups = [], bool $valueIsVisibilityGroup = false ) {
		$this->visible                = $default;
		$this->visibilityGroups       = $groups;
		$this->valueIsVisibilityGroup = $valueIsVisibilityGroup;
	}

}