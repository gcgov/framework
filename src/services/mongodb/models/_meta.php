<?php
namespace gcgov\framework\services\mongodb\models;


use gcgov\framework\services\log;
use gcgov\framework\services\mongodb\attributes\label;
use JetBrains\PhpStorm\ArrayShape;


class _meta
	implements
	\JsonSerializable {

	/** @OA\Property() */
	public ui $ui;

	/**
	 * @OA\Property()
	 * @var uiField[] $fields
	 */
	public array $fields = [];

	/** @OA\Property() */
	public array $labels;

	/** @OA\Property() */
	public ?db $db = null;

	/** @OA\Property() */
	public float $score    = 0;

	private bool $exportDb = false;


	public function __construct( string $className ) {
		$this->ui = new ui();
		$this->generateAttributes( $className );
	}


	public function setDb( \gcgov\framework\services\mongodb\updateDeleteResult $updateDeleteResult ) {
		$this->exportDb = true;
		$this->db       = new db( $updateDeleteResult );
	}


	#[ArrayShape( [ 'ui'     => "\gcgov\framework\services\mongodb\models\ui",
	                'labels' => "array",
	                'fields' => "\gcgov\framework\services\mongodb\models\uiField[]",
	                'db'     => "\gcgov\framework\services\mongodb\models\db|null",
	                'score'  => "float|int"
	] )]
	public function jsonSerialize() : array {
		$export = [
			'ui'     => $this->ui,
			'labels' => $this->labels,
			'fields' => $this->fields,
		];

		if( $this->score != 0 ) {
			$export[ 'score' ] = $this->score;
		}

		if( $this->exportDb ) {
			$export[ 'db' ] = $this->db;
		}

		return $export;
	}


	private function generateAttributes( string $className ) {
		$this->labels = [];
		$this->fields = [];

		try {
			$reflectionClass = new \ReflectionClass( $className );

			foreach( $reflectionClass->getProperties() as $property ) {
				$this->fields[ $property->getName() ] = new uiField();

				//get all attributes for this property
				$propertyAttributes = $property->getAttributes();
				foreach( $propertyAttributes as $propertyAttribute ) {
					if( $propertyAttribute->getName() == label::class ) {
						$labelAttributeInstance                      = $propertyAttribute->newInstance();
						$this->fields[ $property->getName() ]->label = $labelAttributeInstance->label;
						$this->labels[ $property->getName() ]        = $labelAttributeInstance->label;
					}
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoService', 'Generate attribute data failed: ' . $e->getMessage(), $e->getTrace() );
			error_log( $e );
		}
	}

}