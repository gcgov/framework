<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class label {

	/** @var string Human readable field label for ui display and error reporting */
	public string $label = '';


	public function __construct( string $label ) {
		$this->label = $label;
	}

}