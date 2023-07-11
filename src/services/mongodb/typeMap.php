<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeFromTypemapWhenThisClassNotRoot;
use gcgov\framework\services\mongodb\attributes\foreignKey;
use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\tools\reflectionCache;
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

	/** @var string[] $foreignKeyMap */
	public array $foreignKeyMap = [];

	public array $foreignKeyMapEmbeddedFilters = [];

	/** @var string[] $parentContexts  */
	private array $parentContexts = [];


	/**
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	public function __construct( string $rootClassFqn, array $knownFieldPaths = [], array $parentContexts=[] ) {
		$this->root       = $rootClassFqn;
		$this->fieldPaths = $knownFieldPaths;
		$this->parentContexts = $parentContexts;
		$this->generate();
	}


	/**
	 * @throws \gcgov\framework\services\mongodb\exceptions\databaseException
	 */
	private function generate(): void {
		try {
			$reflectionCacheClass = reflectionCache::getReflectionClass( $this->root );
		}
		catch( \ReflectionException $e ) {
			throw new databaseException( 'Failed to load ' . $this->root . ' to generate typemap', 500, $e );
		}

		if( !$reflectionCacheClass->reflectionClass->isSubclassOf( \gcgov\framework\services\mongodb\embeddable::class ) ) {
			return;
		}

		if( $reflectionCacheClass->reflectionClass->isSubclassOf( \gcgov\framework\services\mongodb\model::class ) ) {
			$this->model = true;
			try {
				$reflectionMethod = $reflectionCacheClass->reflectionClass->getMethod( '_getCollectionName' );
				$this->collection = $reflectionMethod->invoke( null );
			}
			catch( \ReflectionException $e ) {
				error_log( $e );
				$this->collection = $reflectionCacheClass->reflectionClass->getStaticPropertyValue( 'COLLECTION', $reflectionCacheClass->name );
			}
		}

		foreach( $reflectionCacheClass->properties as $reflectionCacheProperty ) {
			//skip type mapping this if property is
			//  - excluded from serialization and unserialization
			//  - not typed
			if( ($reflectionCacheProperty->hasAttribute( excludeBsonSerialize::class ) && $reflectionCacheProperty->hasAttribute( excludeBsonUnserialize::class )) || !$reflectionCacheProperty->propertyHasType ) {
				continue;
			}

			//only map \app classes and \gcgov\framework\services\mongodb\models classes
			if( !str_starts_with( $reflectionCacheProperty->propertyTypeNameFQN, '\app' ) && !str_starts_with( $reflectionCacheProperty->propertyTypeNameFQN, '\gcgov\framework\services\mongodb\models' ) ) {
				continue;
			}

			try {
				$propertyTypeReflectionCacheClass = reflectionCache::getReflectionClass( $reflectionCacheProperty->propertyTypeNameFQN );
				if( !$propertyTypeReflectionCacheClass->reflectionClass->isSubclassOf( \gcgov\framework\services\mongodb\embeddable::class )) {
					continue;
				}
			}
			catch( \ReflectionException $e ) {
				throw new databaseException( 'Failed to generate type map for ' . $reflectionCacheProperty->propertyTypeName, 500, $e );
			}

			//create mongo field path key
			$baseFieldPathKey = $reflectionCacheProperty->propertyName;
			if( $reflectionCacheProperty->propertyIsArray ) {
				$baseFieldPathKey .= '.$';
			}

			//add the primary property type
			$this->fieldPaths[ $baseFieldPathKey ] = $reflectionCacheProperty->propertyTypeNameFQN;

			//add foreign field mapping for upserting embedded objects
			if( $reflectionCacheProperty->hasAttribute(foreignKey::class) ) {
				/** @var \gcgov\framework\services\mongodb\attributes\foreignKey $foreignKeyAttributeInstance */
				$foreignKeyAttributeInstance = $reflectionCacheProperty->getAttributeInstance( foreignKey::class );
				$this->foreignKeyMap[ $baseFieldPathKey ]                = $foreignKeyAttributeInstance->propertyName;
				$this->foreignKeyMapEmbeddedFilters[ $baseFieldPathKey ] = $foreignKeyAttributeInstance->embeddedObjectFilter;
			}

			//add the field paths for the property type so that we get a full chain of types
			$propertyParentContexts = [ ...$this->parentContexts, $this->root ];
			if( count($this->parentContexts)>0 && $reflectionCacheProperty->hasAttribute( excludeFromTypemapWhenThisClassNotRoot::class ) ) {
				//log::info( 'MongoTypemap', 'Exclude mapping ' . implode(' > ', $propertyParentContexts) . '.' . $reflectionCacheProperty->propertyName . ' of type ' . $reflectionCacheProperty->propertyTypeNameFQN .' in respect of excludeFromTypemapWhenThisClassNotRoot attribute' );
				continue;
			}
			if( in_array($reflectionCacheProperty->propertyTypeNameFQN, $propertyParentContexts) ) {
				log::warning( 'MongoTypemap', 'Mapping ' . implode(' > ', $propertyParentContexts) . '.' . $reflectionCacheProperty->propertyName . ' of type ' . $reflectionCacheProperty->propertyTypeNameFQN .' may cause an infinite loop. Consider using attribute excludeFromTypemapWhenThisClassNotRoot on the property' );
			}

			$propertyTypeMap = typeMapFactory::get( $reflectionCacheProperty->propertyTypeNameFQN, $propertyParentContexts );
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
