<?php
namespace gcgov\framework\services\mongodb\models;

use gcgov\framework\config;
use gcgov\framework\services\mongodb\attributes\visibility;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\models\_meta\db;
use gcgov\framework\services\mongodb\models\_meta\ui;
use gcgov\framework\services\mongodb\models\_meta\uiField;
use gcgov\framework\services\mongodb\tools\metaAttributeCache;
use gcgov\framework\services\mongodb\tools\reflectionCache;
use JetBrains\PhpStorm\ArrayShape;
use OpenApi\Attributes as OA;

#[OA\Schema]
class _meta
	extends
	\andrewsauder\jsonDeserialize\jsonDeserialize
	implements
	\JsonSerializable {

	#[OA\Property]
	public ui $ui;

	#[OA\Property]
	/** @var \gcgov\framework\services\mongodb\models\_meta\uiField[] $fields */
	public array $fields = [];

	#[OA\Property]
	/** @var string[] $labels */
	public array $labels;

	#[OA\Property]
	/** @var string[] $activeVisibilityGroups */
	public array $activeVisibilityGroups = [];

	#[OA\Property]
	public ?db $db = null;

	#[OA\Property]
	public float $score = 0;

	private bool $exportDb = false;


	public function __construct( ?string $className = null ) {
		$this->ui = new ui();
		if( !empty( $className ) ) {
			$this->generateAttributes( $className );
		}
	}


	public function setDb( \gcgov\framework\services\mongodb\updateDeleteResult $updateDeleteResult ) {
		$this->exportDb = true;
		$this->db       = new db( $updateDeleteResult );
	}


	#[ArrayShape( [ 'ui'     => "\gcgov\framework\services\mongodb\models\ui",
	                'labels' => "array",
	                'activeVisibilityGroups' => "array",
	                'fields' => "\gcgov\framework\services\mongodb\models\uiField[]",
	                'db'     => "\gcgov\framework\services\mongodb\models\db|null",
	                'score'  => "float|int"
	] )]
	public function jsonSerialize(): array {
		$export = [
			'ui' => $this->ui
		];

		//TODO: make sure this is using the actual database it's being used for
		if( isset( config::getEnvironmentConfig()->mongoDatabases[ 0 ] ) ) {
			$mdbConfig = config::getEnvironmentConfig()->mongoDatabases[ 0 ];
			if( $mdbConfig->include_metaLabels ) {
				$export[ 'labels' ] = $this->labels;
			}
			if( $mdbConfig->include_metaFields ) {
				$export[ 'fields' ] = $this->fields;
				$export[ 'activeVisibilityGroups' ] = $this->activeVisibilityGroups;
			}
		}

		if( $this->score!=0 ) {
			$export[ 'score' ] = $this->score;
		}

		if( $this->exportDb ) {
			$export[ 'db' ] = $this->db;
		}

		return $export;
	}


	private function generateAttributes( string $className ): void {
		$labels = metaAttributeCache::getLabels( $className );
		$fields = metaAttributeCache::getFields( $className );

		if( isset( $labels ) && isset( $fields ) ) {
			$this->labels = $labels;
			$this->fields = [];
			foreach($fields as $fieldName=>$field ) {
				$this->fields[ $fieldName ] = clone $field;
			}
			return;
		}

		//cache does not exist
		$this->labels = [];
		$this->fields = [];

		try {
			$reflectionCacheClass = reflectionCache::getReflectionClass( $className );
			foreach( $reflectionCacheClass->properties as $reflectionCacheProperty ) {
				$this->fields[ $reflectionCacheProperty->propertyName ] = new uiField();
				if( $reflectionCacheProperty->hasAttribute( label::class ) ) {
					$labelAttributeInstance                                        = $reflectionCacheProperty->getAttributeInstance( label::class );
					$this->fields[ $reflectionCacheProperty->propertyName ]->label = $labelAttributeInstance->label;
					$this->labels[ $reflectionCacheProperty->propertyName ]        = $labelAttributeInstance->label;
				}
				if( $reflectionCacheProperty->hasAttribute( visibility::class ) ) {
					/** @var visibility $visibilityAttributeInstance */
					$visibilityAttributeInstance                                                    = $reflectionCacheProperty->getAttributeInstance( visibility::class );
					$this->fields[ $reflectionCacheProperty->propertyName ]->visible                = $visibilityAttributeInstance->visible;
					$this->fields[ $reflectionCacheProperty->propertyName ]->visibilityGroups        = $visibilityAttributeInstance->visibilityGroups;
					$this->fields[ $reflectionCacheProperty->propertyName ]->valueIsVisibilityGroup = $visibilityAttributeInstance->valueIsVisibilityGroup;
				}

			}

			metaAttributeCache::set( $className, $this->labels, $this->fields );

		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoService', 'Generate attribute data failed: ' . $e->getMessage(), $e->getTrace() );
		}
	}

}
