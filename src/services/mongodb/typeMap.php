<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\foreignKey;
use gcgov\framework\services\mongodb\exceptions\databaseException;
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

	public bool   $model      = false;
	public string $collection = '';

	/** @var string[] */
	public array $foreignKeyMap = [];

	public array $foreignKeyMapEmbeddedFilters = [];


	/**
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	public function __construct( string $rootClassFqn, array $fieldPaths = [] ) {
		$this->root       = $rootClassFqn;
		$this->fieldPaths = $fieldPaths;
		$this->generate();
	}


	/**
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	private function generate(): void {
		try {
			$reflectionClass = new \ReflectionClass( $this->root );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to load ' . $this->root . ' to generate typemap', 500, $e );
		}

		if( !$reflectionClass->isSubclassOf( \gcgov\framework\services\mongodb\embeddable::class ) ) {
			return;
		}

		if( $reflectionClass->isSubclassOf( \gcgov\framework\services\mongodb\model::class ) ) {
			$this->model = true;
			try {
				$reflectionMethod = $reflectionClass->getMethod( '_getCollectionName' );
				$this->collection = $reflectionMethod->invoke( null );
			}
			catch( \ReflectionException $e ) {
				error_log( $e );
				$this->collection = $reflectionClass->getStaticPropertyValue( 'COLLECTION', $reflectionClass->getName() );
			}
		}

		$reflectionProperties = $reflectionClass->getProperties();
		foreach( $reflectionProperties as $reflectionProperty ) {
			//skip type mapping this if property is
			//  - excluded from serialization
			//  - not typed
			//  - starts with _
			$excludeBsonSerializeAttributes = $reflectionProperty->getAttributes( excludeBsonSerialize::class );
			if( count( $excludeBsonSerializeAttributes )>0 || !$reflectionProperty->hasType() || str_starts_with( $reflectionProperty->getName(), '_' ) ) {
				continue;
			}

			//determine property type - including array type
			//get property type
			$reflectionPropertyType = $reflectionProperty->getType();
			$typeName               = '';
			$typeIsArray            = false;
			if( !( $reflectionPropertyType instanceof \ReflectionUnionType ) ) {
				$typeName = $reflectionPropertyType->getName();
			}

			//handle typed arrays
			if( $typeName=='array' ) {
				//get type  from @var doc block
				$typeName    = typeHelpers::getVarTypeFromDocComment( $reflectionProperty->getDocComment() );
				$typeIsArray = true;
			}
			$typeClassFqn                          = typeHelpers::classNameToFqn( $typeName );

			if( !str_starts_with( $typeClassFqn, '\app' ) && !str_starts_with( $typeClassFqn, '\gcgov\framework\services\mongodb\models' ) ) {
				continue;
			}

			try {
				$rPropertyClass = new \ReflectionClass( $typeClassFqn );
				if( !$rPropertyClass->isSubclassOf( embeddable::class ) ) {
					continue;
				}
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to generate type map for ' . $typeName, 500, $e );
			}

			//create mongo field path key
			$baseFieldPathKey = $reflectionProperty->getName();
			if( $typeIsArray ) {
				$baseFieldPathKey .= '.$';
			}

			//add the primary property type
			$this->fieldPaths[ $baseFieldPathKey ] = $typeClassFqn;

			//add foreign field mapping for upserting embedded objects
			$foreignKeyAttributes = $reflectionProperty->getAttributes( foreignKey::class );
			if( $foreignKeyAttributes>0 ) {
				foreach( $foreignKeyAttributes as $foreignKeyAttribute ) {
					/** @var \gcgov\framework\services\mongodb\attributes\foreignKey $fkAttribute */
					$fkAttribute                                             = $foreignKeyAttribute->newInstance();
					$this->foreignKeyMap[ $baseFieldPathKey ]                = $fkAttribute->propertyName;
					$this->foreignKeyMapEmbeddedFilters[ $baseFieldPathKey ] = $fkAttribute->embeddedObjectFilter;
				}
			}

			//add the field paths for the property type so that we get a full chain of types
			$propertyTypeMap = typeMapFactory::get( $typeClassFqn );
			foreach( $propertyTypeMap->fieldPaths as $subFieldPathKey => $subFieldTypeClass ) {
				$this->fieldPaths[ $baseFieldPathKey . '.' . $subFieldPathKey ] = $subFieldTypeClass;
			}
			foreach( $propertyTypeMap->foreignKeyMap as $subFieldPathKey => $fkPropertyName ) {
				$this->foreignKeyMap[ $baseFieldPathKey . '.' . $subFieldPathKey ]                = $fkPropertyName;
				$this->foreignKeyMapEmbeddedFilters[ $baseFieldPathKey . '.' . $subFieldPathKey ] = $propertyTypeMap->foreignKeyMapEmbeddedFilters[ $subFieldPathKey ];
			}

		}
	}


	#[ArrayShape( [
		'root'       => "string",
		'fieldPaths' => "string[]"
	] )]
	public function toArray(): array {
		return [
			'root'       => $this->root,
			'fieldPaths' => $this->fieldPaths
		];
	}

}