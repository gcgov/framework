<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_CLASS )]
class collection {

	/** @var string MongoDB Collection name */
	public string $collection = '';

	/** @var string Human readable label for ui display and error reporting */
	public string $humanName = '';

	/** @var string Plural version of $this->label */
	public string $humanNamePlural = '';


	public function __construct( string $collection, string $humanName, string $humanNamePlural ) {
		$this->collection      = $collection;
		$this->humanName       = $humanName;
		$this->humanNamePlural = $humanNamePlural;
	}

}