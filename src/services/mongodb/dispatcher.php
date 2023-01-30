<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\attributes\deleteCascade;
use gcgov\framework\services\mongodb\tools\reflectionCache;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheClass;
use JetBrains\PhpStorm\ArrayShape;

abstract class dispatcher
	extends embeddable {

	private static array $_indexesToCreate = [];


	public static function _getUpdateEmbeddedMongoActions( object $object ): array {
		log::info( 'Dispatch_updateEmbedded', 'Start _updateEmbedded for ' . $object::class . ' ' . $object->_id );

		//the type of object we are updating
		$updateType = typeHelpers::classNameToFqn( get_class( $object ) );

		//get all model typemaps
		$allTypeMaps = typeMapFactory::getAllModelTypeMaps();

		/**
		 * @see https://docs.mongodb.com/php-library/current/reference/method/MongoDBCollection-bulkWrite/
		 * All bulk actions will go into this associative array where the first level key is the collection name and the value is an array of the bulk actions.
		 *     EX: [ 'project'=>[ ['updateMany'=>[ $filter, $update, $options], ['updateMany'=>[ $filter, $update, $options] ] ] ]
		 */
		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths )===0 || $typeMap->root==$updateType ) {
				continue;
			}

			$collectionName = $typeMap->collection;

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				//this field on this collection embeds the object being updated
				if( $updateType==$fieldPath ) {

					//add update queue for this collection if a queue does not exist
					if( !isset( $mongoActions[ $collectionName ] ) ) {
						$mongoActions[ $collectionName ] = [];
					}

					if( isset( $typeMap->foreignKeyMap[ $fieldKey ] ) ) {
						$foreignKey = $typeMap->foreignKeyMap[ $fieldKey ];

						//skip updating the object if the foreign key filter doesn't match
						if( isset( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] ) && count( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] )>0 ) {
							foreach( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] as $embeddedPropertyName => $inclusionValue ) {
								if( $object->$embeddedPropertyName!=$inclusionValue ) {
									log::info( 'Dispatch_updateEmbedded', '--Not doing anything with this because object is filtered out of: ' . $collectionName . ' ' . $fieldKey . ' => ' . $typeMap->foreignKeyMap[ $fieldKey ] . ' because field ' . $embeddedPropertyName . ' is not equal to ' . $inclusionValue );
									continue 2;
								}
							}
						}

						//insert object into a parent document where the foreign key matches and it isn't already on the document
						if( $object->$foreignKey!==null ) {
							$primaryFilterKey = self::getFieldPathToFirstParentModel( $fieldKey, $typeMap );
							if( strlen( $primaryFilterKey )>0 ) {
								$primaryFilterKey .= '.';
							}
							$primaryFilterKey .= '_id';
							$primaryFilterKey = str_replace( '.$', '', $primaryFilterKey );

							$action                                           = self::_generateDeleteAction( $collectionName, $fieldKey, $object->_id );
							$action[ 'updateMany' ][ 0 ][ $primaryFilterKey ] = [ '$ne' => $object->$foreignKey ];

							$mongoActions[ $collectionName ][] = $action;
						}

					}

					//update existing embedded documents
					log::info( 'Dispatch_updateEmbedded', '--update collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $updateType );
					$mongoActions[ $collectionName ][] = self::_generateUpdateAction( $collectionName, $fieldKey, $object );
				}
			}
		}

		return $mongoActions;
	}


	/**
	 * Find all models where this object type is embedded and update the embedded document with the provided object
	 *      Matches on _id and handles "infinite" nested arrays of objects
	 *
	 * @param object                       $object
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _updateEmbedded( object $object, ?\MongoDB\Driver\Session $mongoDbSession = null ): array {

		$mongoActions = self::_getUpdateEmbeddedMongoActions( $object );

		//run bulk write for all updates
		return self::_runMongoActions( $mongoActions, 'Dispatch_updateEmbedded', $mongoDbSession );
	}


	public static function _getDeleteEmbeddedMongoActions( string $deleteType, \MongoDB\BSON\ObjectId $_id ): array {
		log::info( 'Dispatch_deleteEmbedded', 'Start _deleteEmbedded for ' . $deleteType . ' - ' . $_id );

		//the type of object we are updating
		$deleteType = '\\' . trim( $deleteType, '/\\' );

		//get all model typemaps
		$allTypeMaps = typeMapFactory::getAllModelTypeMaps();

		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths )===0 || $typeMap->root==$deleteType ) {
				continue;
			}

			$collectionName = $typeMap->collection;

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $deleteType==$fieldPath ) {
					if( !isset( $mongoActions[ $collectionName ] ) ) {
						$mongoActions[ $collectionName ] = [];
					}

					log::info( 'Dispatch_deleteEmbedded', '-- generate delete for item on collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $deleteType );

					$mongoActions[ $collectionName ][] = self::_generateDeleteAction( $collectionName, $fieldKey, $_id );
				}
			}
		}

		return $mongoActions;
	}


	/**
	 * Find all models where this object type is embedded and update the embedded document with the provided object
	 *      Matches on _id and handles "infinite" nested arrays of objects
	 *
	 * @param string                 $deleteType    Class FQN to remove - must be a class that extends
	 *                                              \gcgov\framework\services\mongodb\model
	 * @param \MongoDB\BSON\ObjectId $_id
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _deleteEmbedded( string $deleteType, \MongoDB\BSON\ObjectId $_id ): array {

		$mongoActions = self::_getDeleteEmbeddedMongoActions( $deleteType, $_id );

		//run bulk write for all updates
		return self::_runMongoActions( $mongoActions, 'Dispatch_deleteEmbedded' );
	}


	/**
	 * If this model contains a delete cascade property, run the cascade
	 *
	 * @param mixed $object
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _deleteCascade( mixed $object ): array {
		$deleteType = get_class( $object );

		log::info( 'Dispatch_deleteCascade', 'Start cascade deletes for ' . $deleteType );

		$deleteResponses = [];

		//find fields marked with deleteCascade attribute on $object (must be of type _model)
		try {
			$reflectionCacheClass = reflectionCache::getReflectionClass( $deleteType );
			$propertiesWithDeleteCascadeAttribute = $reflectionCacheClass->getPropertiesWithAttribute( deleteCascade::class );
		}
		catch( \ReflectionException $e ) {
			log::error( 'Dispatch_deleteCascade', $e->getMessage(), $e->getTrace() );
			throw new modelException( 'Reflection failed on class ' . $deleteType, 500, $e );
		}

		foreach( $propertiesWithDeleteCascadeAttribute as $propertyName=>$reflectionCacheProperty ) {
			if($reflectionCacheProperty->propertyIsArray) {
				log::info( 'Dispatch_deleteCascade', '-- ' . count( $object->$propertyName ) . ' objects to cascade' );
				foreach( $object->$propertyName as $objectToDelete ) {
					self::_doCascadeDeleteItem( $objectToDelete );
				}
			}
			else {
				self::_doCascadeDeleteItem( $object->$propertyName );
			}
		}

		log::info( 'Dispatch_deleteCascade', 'Finish cascade deletes for ' . $deleteType );

		return $deleteResponses;
	}


	/**
	 * @return \MongoDB\UpdateResult[]
	 */
	private static function _doCascadeDeleteItem( $item ): array {
		$deleteResponses = [];

		if( !( $item instanceof factory ) ) {
			log::info( 'Dispatch_deleteCascade', '-- do manual cascade of ' . get_class( $item ) );
			$deleteResponses = self::_deleteCascade( $item );
		}
		else {
			//do delete on property
			$objectTypeToDelete = get_class( $item );
			log::info( 'Dispatch_deleteCascade', '-- do cascade delete of ' . $objectTypeToDelete . ' ' . $item->_id );
			//TODO: change this to a transaction bulk write
			try {
				//run a cascade delete check on the item
				//delete the item
				//delete embedded copies of the item
				$deleteResponses[] = $objectTypeToDelete::delete( $item->_id );
			}
			catch( modelException $e ) {
				log::info( 'Dispatch_deleteCascade', '--- error deleting item: ' . $e->getMessage() );
			}
		}

		return $deleteResponses;
	}


	public static function _getInsertEmbeddedMongoActions( object $objectToInsert ): array {
		log::info( 'Dispatch_insertEmbedded', 'Start _insertEmbedded for ' . $objectToInsert::class . ' ' . $objectToInsert->_id );

		//the type of object we are updating
		$updateType = typeHelpers::classNameToFqn( get_class( $objectToInsert ) );

		//get all model typemaps
		$allTypeMaps = typeMapFactory::getAllModelTypeMaps();

		//update filters and actions collection
		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths )===0 || $typeMap->root==$updateType ) {
				continue;
			}

			$collectionName = $typeMap->collection;

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $updateType==$fieldPath && isset( $typeMap->foreignKeyMap[ $fieldKey ] ) ) {
					$foreignKey = $typeMap->foreignKeyMap[ $fieldKey ];

					//TODO: enable inserting when not in array?
					//if not nested in array, we skip it for now
					if( !str_ends_with( $fieldKey, '$' ) ) {
						log::info( 'Dispatch_insertEmbedded', '--Not doing anything with this because object is not in an array: ' . $collectionName . ' ' . $fieldKey . ' => ' . $typeMap->foreignKeyMap[ $fieldKey ] );
						continue;
					}

					//don't try if the foreign key for the collection is null on the object we're inserting
					if( $objectToInsert->$foreignKey===null ) {
						continue;
					}

					//exclude inserting objects that do not match the filter
					if( isset( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] ) && count( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] )>0 ) {
						foreach( $typeMap->foreignKeyMapEmbeddedFilters[ $fieldKey ] as $embeddedPropertyName => $inclusionValue ) {
							if( $objectToInsert->$embeddedPropertyName!=$inclusionValue ) {
								log::info( 'Dispatch_insertEmbedded', '--Not doing anything with this because object is filtered out of: ' . $collectionName . ' ' . $fieldKey . ' => ' . $typeMap->foreignKeyMap[ $fieldKey ] . ' because field ' . $embeddedPropertyName . ' is not equal to ' . $inclusionValue );
								continue 2;
							}
						}
					}

					//build primary key filter to filter the parent collection to objects that match the foreign key of the object we are inserting
					$primaryFilterKey = self::getFieldPathToFirstParentModel( $fieldKey, $typeMap );

					if( strlen( $primaryFilterKey )>0 ) {
						$primaryFilterKey .= '.';
					}
					$primaryFilterKey .= '_id';
					$primaryFilterKey = str_replace( '.$', '', $primaryFilterKey );

					$objectArrayFilterKey = str_replace( '.$', '', $fieldKey );

					$updateKey = substr( $fieldKey, 0, -2 );
					$options   = [];

					//handle complex paths to solve mongo "too many positional elements error"
					if( substr_count( $updateKey, '$' )>1 ) {
						$updateKey = \gcgov\framework\services\mongodb\dispatcher::convertFieldPathToComplexUpdate( $updateKey, true, 'arrayFilter' );
						$options   = [
							'arrayFilters' => [
								[ 'arrayFilter._id' => $objectToInsert->$foreignKey ]
							]
						];
					}

					//filter to mongo $push object into collection document that should contain it based on foreign key matching
					$filter = [
						$primaryFilterKey     => $objectToInsert->$foreignKey,
						$objectArrayFilterKey => [
							'$not' => [
								'$elemMatch' => [
									'_id' => $objectToInsert->_id
								]
							]
						]
					];

					$update = [
						'$push' => [
							$updateKey => $objectToInsert
						]
					];

					//store the filter and update data on the collection so that we can do bulk writes
					if( !isset( $mongoActions[ $collectionName ] ) ) {
						$mongoActions[ $collectionName ] = [];
					}
					$mongoActions[ $collectionName ][] = [
						'updateOne' => [
							$filter,
							$update,
							$options
						]
					];
				}
			}
		}

		return $mongoActions;
	}


	/**
	 * Inject this object into all associative arrays where foreign keys have been mapped on the parent
	 *
	 * @param object                       $objectToInsert
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _insertEmbedded( object $objectToInsert, ?\MongoDB\Driver\Session $mongoDbSession = null ): array {

		$mongoActions = self::_getInsertEmbeddedMongoActions( $objectToInsert );

		//inject or update inspection
		return self::_runMongoActions( $mongoActions, 'Dispatch_insertEmbedded', $mongoDbSession );
	}


	/**
	 * //run bulk write for mongo actions array
	 *
	 * @param array                        $mongoActions
	 * @param string                       $logChannel
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return updateDeleteResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	protected static function _runMongoActions( array $mongoActions, string $logChannel, ?\MongoDB\Driver\Session $mongoDbSession = null ): array {
		$logging = config::getEnvironmentConfig()->type=='local';

		$sessionParent = false;

		$updateInsertDeleteResults = [];

		if( count( $mongoActions )>0 ) {
			if( $logging ) {
				log::info( $logChannel, '-run bulk write ' );
			}
			try {
				$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

				if( !isset( $mongoDbSession ) ) {
					$sessionParent  = true;
					$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
					$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 5000 ] );
				}

				foreach( $mongoActions as $collectionName => $queries ) {
					log::info( $logChannel, '---touch collection ' . $collectionName );

					$result = $mdb->db->$collectionName->bulkWrite( $queries, [ 'session' => $mongoDbSession ] );

					log::info( $logChannel, '----Matched: ' . $result->getMatchedCount() );
					log::info( $logChannel, '----Mod: ' . $result->getModifiedCount() );

					$updateInsertDeleteResults[] = new updateDeleteResult( $result );

					//create index files in local environment
					if( \gcgov\framework\config::getEnvironmentConfig()->isLocal() ) {
						foreach( $queries as $operations ) {
							foreach( $operations as $operationType => $filterUpdateOptions ) {
								$index     = [];
								$indexName = 'gcgov';
								foreach( $filterUpdateOptions[ 0 ] as $field => $value ) {
									if($field=='_id') {
										continue;
									}
									$key = $field;
									if( is_array( $value ) && !str_ends_with( $key, '_id' ) ) {
										$key .= '._id';
									}
									$index[ $key ] = 1;
									$indexName     .= '_' . $key . '_' . $index[ $key ];

								}

								//get keys to make sure we have a unique set
								if( !isset( self::$_indexesToCreate[ $collectionName.$indexName ] ) ) {
									log::info( 'index', 'Create on ' . $collectionName.': '.$indexName );

									self::$_indexesToCreate[ $collectionName.$indexName ] = [
										'collection' => $collectionName,
										'index'      => $index,
										'options'    => [ 'name' => $indexName, 'sparse' => 1 ]
									];

								}
							}


						}
					}
				}

				if( \gcgov\framework\config::getEnvironmentConfig()->isLocal() ) {
					if( count( self::$_indexesToCreate )>0 ) {
						$filename = \gcgov\framework\config::getTempDir() . '/create-indexes-' . microtime() . '.js';

						file_put_contents( $filename, 'var indexes=' . json_encode( array_values(self::$_indexesToCreate) ) );

						self::$_indexesToCreate = [];
					}
				}

				if( $sessionParent ) {
					$mongoDbSession->commitTransaction();
				}


			}
			catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
				error_log( $e );
				if( $sessionParent ) {
					$mongoDbSession->abortTransaction();
				}
				log::error( $logChannel, $e->getMessage() );
				throw new \gcgov\framework\exceptions\modelException( 'Database error: ' . $e->getMessage(), 500, $e );
			}
		}

		return $updateInsertDeleteResults;

	}


	private static function convertFieldPathToComplexUpdate( string $fieldPath, bool $arrayFilter = true, string $arrayFilterKey = 'arrayFilter' ): string {
		//convert $fieldPath  `
		// from     `inspections.$.scheduleRequests.$.comments.$`
		// to       `inspections.$[].scheduleRequests.$[].comments.$[arrayFilter]`
		$pathParts          = explode( '.', $fieldPath );
		$reversedPathParts  = array_reverse( $pathParts );
		$foundPrimaryTarget = false;
		foreach( $reversedPathParts as $i => $part ) {
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part==='$' ) {
				$foundPrimaryTarget = true;
				if( $arrayFilter ) {
					$reversedPathParts[ $i ] = '$[' . $arrayFilterKey . ']';
				}
				else {
					unset( $reversedPathParts[ $i ] );
				}
			}
			elseif( $foundPrimaryTarget && $part==='$' ) {
				$reversedPathParts[ $i ] = '$[]';
			}
		}
		$complexPathParts = array_reverse( $reversedPathParts );

		return implode( '.', $complexPathParts );
	}


	/**
	 * @param string                 $collectionName
	 * @param string                 $pathToUpdate
	 * @param \MongoDB\BSON\ObjectId $_id
	 *
	 * @return array[]
	 */
	#[ArrayShape( [ 'updateMany' => "array" ] )]
	private static function _generateDeleteAction( string $collectionName, string $pathToUpdate, \MongoDB\BSON\ObjectId $_id ): array {

		$filter = [];

		$options = [
			'upsert' => false
		];

		//check whether this is an array or nullable
		if( substr( $pathToUpdate, -1 )==='$' ) {
			//remove item from array

			//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
			$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

			$filter[ $filterPath . '._id' ] = $_id;

			$complexPath = self::convertFieldPathToComplexUpdate( $pathToUpdate, false );

			$update = [
				'$pull' => [
					$complexPath => [ '_id' => $_id ]
				]
			];
		}
		else {
			//simple update to null

			//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
			$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

			$filter[ $filterPath . '._id' ] = $_id;

			$updatePath = str_replace( '.$', '.$[]', $pathToUpdate );

			$update = [
				'$set' => [
					$updatePath => null
				]
			];
		}

		return [ 'updateMany' => [ $filter, $update, $options ] ];

	}


	/**
	 * @param string $collectionName
	 * @param string $pathToUpdate
	 * @param object $updateObject
	 *
	 * @return array
	 */
	#[ArrayShape( [ 'updateMany' => "array" ] )]
	private static function _generateUpdateAction( string $collectionName, string $pathToUpdate, object $updateObject ): array {

		//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
		$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

		$filter = [
			$filterPath . '._id' => $updateObject->_id
		];

		$options = [
			'upsert' => false
		];

		$complex = self::buildUpdateKeyArrayFilters( $pathToUpdate, true, $updateObject->_id );

		//complex update
		$update = [
			'$set' => [
				$complex[ 'complexPath' ] => $updateObject
			]
		];

		if(count($complex[ 'arrayFilters' ])>0) {
			$options[ 'arrayFilters' ] = $complex[ 'arrayFilters' ];
		}

		return [ 'updateMany' => [ $filter, $update, $options ] ];

	}


	private static function convertFieldPathToFilterPath( string $fieldPath ): string {
		return str_replace( '.$', '', $fieldPath );
	}


	#[ArrayShape( [ 'complexPath'  => "string",
	                'arrayFilters' => "array"
	] )]
	private static function buildUpdateKeyArrayFilters( string $fieldPath, bool $useArrayFilter = true, mixed $arrayFilterValue = null ): array {
		//convert $fieldPath  `
		// from     `inspections.$.scheduleRequests.$.comments.$`
		// to       `inspections.$[arrayFilter2].scheduleRequests.$[arrayFilter1].comments.$[arrayFilter0]`
		if(!str_contains($fieldPath, '$')) {
			return [
				'complexPath'  => $fieldPath,
				'arrayFilters' => []//array_reverse( $arrayFilters )
			];
		}
		$pathParts         = explode( '.', $fieldPath );
		$reversedPathParts = array_reverse( $pathParts );

		$arrayFilters = [];

		$previousParts = [];

		$foundPrimaryTarget = false;
		foreach( $reversedPathParts as $i => $part ) {
			$arrayFilterIndex = count( $arrayFilters );
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part==='$' ) {
				$foundPrimaryTarget = true;
				if( $useArrayFilter ) {
					$reversedPathParts[ $i ]           = '$[arrayFilter' . $arrayFilterIndex . ']';
					$arrayFilters[ $arrayFilterIndex ] = $previousParts;
				}
				else {
					unset( $reversedPathParts[ $i ] );
				}
			}
			elseif( $foundPrimaryTarget && $part==='$' ) {
				$reversedPathParts[ $i ]           = '$[arrayFilter' . $arrayFilterIndex . ']';
				$arrayFilters[ $arrayFilterIndex ] = $previousParts;
			}
			else {
				$previousParts[] = $part;
			}

		}
		$oldcomplexPathParts = array_reverse( $reversedPathParts );
		$complexPathParts = self::convertFieldPathToComplexUpdate( $fieldPath, $useArrayFilter );

		foreach( $arrayFilters as $i => $arrayFilter ) {
			$arrayFilter[]      = 'arrayFilter' . $i;
			$arrayFilters[ $i ] = [
				implode( '.', array_reverse( $arrayFilter ) ) . '._id' => $arrayFilterValue
			];
		}

		return [
			'complexPath'  => $complexPathParts,//implode( '.', $complexPathParts ),
			'arrayFilters' => [ ['arrayFilter._id'=>$arrayFilterValue] ]//array_reverse( $arrayFilters )
		];

	}


	private static function getFieldPathToFirstParentModel( string $startingFieldPath, typeMap $typeMap ): string {
		if( substr_count( $startingFieldPath, '.' )>1 ) {
			$last             = strrpos( $startingFieldPath, '.' );
			$nextToLast       = strrpos( $startingFieldPath, '.', $last - strlen( $startingFieldPath ) - 1 );
			$parentObjectPath = substr( $startingFieldPath, 0, $nextToLast );

			//check if the parent object containing the array is a model or just embeddable
			$parentObjectType       = $typeMap->fieldPaths[ $parentObjectPath ];
			$parentObjectReflection = new \ReflectionClass( $parentObjectType );
			if( $parentObjectReflection->isSubclassOf( \gcgov\framework\services\mongodb\model::class ) ) {
				return $parentObjectPath;
			}
			else {
				return self::getFieldPathToFirstParentModel( $parentObjectType, $typeMap );
			}
		}
		else {
			return '';
		}
	}

}