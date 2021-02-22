<?php
namespace gcgov\framework\services\mongodb\models;


use gcgov\framework\services\mongodb\attributes\label;


class _meta {

	/** @OA\Property() */
	public ui $ui;

	/** @OA\Property() */
	public db $db;

	/** @OA\Property() */
	public array $labels;


	public function __construct( string $className ) {
		$this->ui     = new ui();
		$this->db     = new db();
		$this->labels = $this->generateLabels( $className );
	}


	private function generateLabels( string $className ) : array {
		$labels = [];

		try {
			$reflectionClass = new \ReflectionClass( $className );

			foreach( $reflectionClass->getProperties() as $property ) {
				$attributes = $property->getAttributes( label::class );

				foreach( $attributes as $attribute ) {
					$labelAttribute                 = $attribute->newInstance();
					$labels[ $property->getName() ] = $labelAttribute->label;
				}
			}
		}
		catch( \ReflectionException $e ) {
			error_log( $e );
		}

		return $labels;
	}

}