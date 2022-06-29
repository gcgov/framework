<?php

namespace gcgov\framework\services\mongodb;


use JetBrains\PhpStorm\ArrayShape;


/**
 * Class typeMap
 * @see     https://www.php.net/manual/en/mongodb.persistence.deserialization.php
 * @package gcgov\framework\services\mongodb
 */
class typeMap {

	public string $root = '';

	/** @var string[] */
	public array $fieldPaths = [];

	public bool $model = false;
	public string $collection = '';

	/** @var string[] */
	public array $foreignKeyMap = [];


	public function __construct( string $root, array $fieldPaths = [] ) {
		$this->root       = $root;
		$this->fieldPaths = $fieldPaths;
	}


	#[ArrayShape( [
		'root'       => "string",
		'fieldPaths' => "string[]"
	] )]
	public function toArray() : array {
		return [
			'root'       => $this->root,
			'fieldPaths' => $this->fieldPaths
		];
	}


}