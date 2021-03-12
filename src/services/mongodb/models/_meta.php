<?php
namespace gcgov\framework\services\mongodb\models;


use gcgov\framework\services\mongodb\attributes\label;


class _meta
	implements
	\JsonSerializable {

	/** @OA\Property() */
	public ui $ui;

	/** @OA\Property() */
	public ?db $db = null;

	/** @OA\Property() */
	public float $score = 0;

	/** @OA\Property() */
	public array $labels;

	private bool $exportDb = false;


	public function __construct( string $className ) {
		$this->ui     = new ui();
		$this->labels = $this->generateLabels( $className );
	}


	public function setDb( \gcgov\framework\services\mongodb\updateDeleteResult $updateDeleteResult ) {
		$this->exportDb = true;
		$this->db       = new db( $updateDeleteResult );
	}


	public function jsonSerialize() : array {
		$export = [
			'ui'     => $this->ui,
			'labels' => $this->labels
		];

		if($this->score!=0) {
			$export['score'] = $this->score;
		}


		if( $this->exportDb ) {
			$export[ 'db' ] = $this->db;
		}

		return $export;
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