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

	/**
	 * @var string[]
	 */
	public array $fieldPaths = [];


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


	/**
	 * @param  string  $fieldPathKey
	 * @param  array   $fieldPathTypeMap
	 *
	 * @return void
	 */
	public function addEmbeddedType( string $fieldPathKey, array $fieldPathTypeMap ) : void {
		$this->fieldPaths[ $fieldPathKey ] = $fieldPathTypeMap[ 'root' ];

		if( isset( $fieldPathTypeMap[ 'fieldPaths' ] ) && count( $fieldPathTypeMap[ 'fieldPaths' ] ) > 0 ) {
			foreach( $fieldPathTypeMap[ 'fieldPaths' ] as $key => $classFqn ) {
				$this->fieldPaths[ $fieldPathKey . '.' . $key ] = $classFqn;
			}
		}
	}

}