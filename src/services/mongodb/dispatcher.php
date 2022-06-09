<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\attributes\deleteCascade;
use gcgov\framework\services\mongodb\tools\typeMapCache;
use JetBrains\PhpStorm\ArrayShape;


abstract class dispatcher
	extends
	embeddable {

	/**
	 * Find all models where this object type is embedded and update the embedded document with the provided object
	 *      Matches on _id and handles "infinite" nested arrays of objects
	 *
	 * @param $object
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _updateEmbedded( $object ) : array {
		log::info( 'Dispatch_updateEmbedded', 'Start _updateEmbedded for ' . $object::class .' '. $object->_id );
		$embeddedUpdates = [];

		//the type of object we are updating
		$updateType = '\\' . trim( get_class( $object ), '/\\' );

		//get all model typemaps
		$allTypeMaps = self::getAllTypeMaps();

		/**
		 * @see https://docs.mongodb.com/php-library/current/reference/method/MongoDBCollection-bulkWrite/
		 * All bulk actions will go into this associative array where the first level key is the collection name and the value is an array of the bulk actions.
		 *     EX: [ 'project'=>[ ['updateMany'=>[ $filter, $update, $options], ['updateMany'=>[ $filter, $update, $options] ] ] ]
		 */
		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $collectionName => $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths ) === 0 || $typeMap->root == $updateType ) {
				continue;
			}

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				//this field on this collection embeds the object being updated
				if( $updateType == $fieldPath ) {

					//add update queue for this collection if a queue does not exist
					if(!isset($mongoActions[$collectionName])) {
						$mongoActions[$collectionName] = [];
					}

					//check if this object is embeded using a foreign key
					if( isset( $typeMap->foreignKeyMap[ $fieldKey ] ) ) {
						$foreignKey = $typeMap->foreignKeyMap[ $fieldKey ];

						if($object->$foreignKey!==null) {

							$filterPath = self::convertFieldPathToFilterPath( $fieldKey );

							$action = self::_generateDeleteAction($collectionName, $fieldKey, $object->_id );

							$primaryFilterKey = self::getFieldPathToFirstParentModel( $fieldKey, $typeMap );
							if( strlen( $primaryFilterKey ) > 0 ) {
								$primaryFilterKey .= '.';
							}
							$primaryFilterKey .= '_id';
							$primaryFilterKey = str_replace( '.$', '', $primaryFilterKey );
							$action['updateMany'][ 0 ][$primaryFilterKey] = [ '$ne'=> $object->$foreignKey ];
//							$action = [
//								'updateMany' => [
//									[
//										$filterPath . '._id' =>  $object->_id,
//										'_id'=>[ '$ne'=> $object->$foreignKey ]
//									],
//									[
//										'$pull'=>[
//											$filterPath => [
//												'_id'=>$object->_id
//											]
//										]
//									],
//									[
//										'upsert'=>false
//									]
//								]
//							];
							$mongoActions[$collectionName][] = $action;
						}
					}

					log::info( 'Dispatch_updateEmbedded', '--update collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $updateType );
					$mongoActions[$collectionName][] = self::_generateUpdateAction( $collectionName, $fieldKey, $object );
				}
			}
		}

		//run bulk write for all updates
		$embeddedUpdates = self::_runMongoActions( $mongoActions, 'Dispatch_updateEmbedded' );


		return $embeddedUpdates;
	}


	/**
	 * Find all models where this object type is embedded and update the embedded document with the provided object
	 *      Matches on _id and handles "infinite" nested arrays of objects
	 *
	 * @param  string                  $deleteType  Class FQN to remove - must be a class that extends
	 *                                              \gcgov\framework\services\mongodb\model
	 * @param  \MongoDB\BSON\ObjectId  $_id
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _deleteEmbedded( string $deleteType, \MongoDB\BSON\ObjectId $_id ) : array {
		log::info( 'Dispatch_deleteEmbedded', 'Start _deleteEmbedded for ' . $deleteType.' - '. $_id );

		//the type of object we are updating
		$deleteType = '\\' . trim( $deleteType, '/\\' );

		//get all model typemaps
		$allTypeMaps = self::getAllTypeMaps();


		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $collectionName => $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths ) === 0 || $typeMap->root == $deleteType ) {
				continue;
			}

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $deleteType == $fieldPath ) {
					if(!isset($mongoActions[$collectionName])) {
						$mongoActions[$collectionName] = [];
					}

					log::info( 'Dispatch_deleteEmbedded', '-- generate delete for item on collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $deleteType );
					//TODO: change this to a transaction bulk write
					$mongoActions[$collectionName][] = self::_generateDeleteAction( $collectionName, $fieldKey, $_id );
				}
			}
		}

		//run bulk write for all updates
		$embeddedDeletes = self::_runMongoActions( $mongoActions, 'Dispatch_deleteEmbedded' );


		return $embeddedDeletes;
	}


	/**
	 * If this model contains a delete cascade property, run the cascade
	 *
	 * @param  string  $objectToDelete
	 *
	 * @return \MongoDB\UpdateResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _deleteCascade( mixed $object ) : array {
		$deleteType = get_class($object);

		log::info( 'Dispatch_deleteCascade', 'Start cascade deletes for ' . $deleteType );

		/** @var attributes\deleteCascade[] $deleteCascadeAttributes */
		$deleteCascadeAttributes = [];

		$deleteResponses = [];

		//find fields marked with deleteCascade attribute on $object (must be of type _model)
		try {
			$reflectionClass = new \ReflectionClass( $deleteType );

			foreach( $reflectionClass->getProperties() as $property ) {
				$attributes = $property->getAttributes( deleteCascade::class );

				//this is a delete cascade field
				if( count( $attributes ) > 0 ) {
					$deleteCascadeAttributes[ $property->getName() ] = $attributes[ 0 ]->newInstance();
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'Dispatch_deleteCascade', $e->getMessage(), $e->getTrace() );
			throw new modelException( 'Reflection failed on class ' . $deleteType, 500, $e );
		}

		foreach( $deleteCascadeAttributes as $propertyName => $deleteCascadeAttribute ) {

			if( gettype( $object->$propertyName ) === 'array' ) {
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
	private static function _doCascadeDeleteItem( $item ) : array {
		$deleteResponses = [];

		if(!($item instanceof factory)) {
			log::info('Dispatch_deleteCascade', '-- do manual cascade of '. get_class($item) );
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
				log::info('Dispatch_deleteCascade', '--- error deleting item: '.$e->getMessage());
			}
		}

		return $deleteResponses;
	}


	/**
	 * Inject this object into all associative arrays where foreign keys have been mapped on the parent
	 *
	 * @param $objectToInsert
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function _insertEmbedded( $objectToInsert ) : array {
		log::info( 'Dispatch_insertEmbedded', 'Start _insertEmbedded for ' . $objectToInsert::class .' ' . $objectToInsert->_id);

		//the type of object we are updating
		$updateType = '\\' . trim( get_class( $objectToInsert ), '/\\' );

		//get all model typemaps
		$allTypeMaps = \gcgov\framework\services\mongodb\dispatcher::getAllTypeMaps();

		//update filters and actions collection
		$mongoActions = [];

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $collectionName => $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths ) === 0 || $typeMap->root == $updateType ) {
				continue;
			}

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $updateType == $fieldPath && isset( $typeMap->foreignKeyMap[ $fieldKey ] ) ) {
					$foreignKey = $typeMap->foreignKeyMap[ $fieldKey ];

					//TODO: enable inserting when not in array?
					//if not nested in array, we skip it for now
					if( substr( $fieldKey, -1, 1 ) != '$' ) {
						log::info( 'Dispatch_insertEmbedded', '--Not doing anything with this because object is not in an array: ' . $collectionName . ' ' . $fieldKey . ' => ' . $typeMap->foreignKeyMap[ $fieldKey ] );
						continue;
					}

					//build primary key filter to filter the parent collection to objects that match the foreign key of the object we are inserting
					$primaryFilterKey = self::getFieldPathToFirstParentModel( $fieldKey, $typeMap );

					if( strlen( $primaryFilterKey ) > 0 ) {
						$primaryFilterKey .= '.';
					}
					$primaryFilterKey .= '_id';
					$primaryFilterKey = str_replace( '.$', '', $primaryFilterKey );

					$objectArrayFilterKey = str_replace( '.$', '', $fieldKey );

					$updateKey = substr( $fieldKey, 0, -2 );
					$options   = [];

					//handle complex paths to solve mongo "too many positional elements error"
					if( substr_count( $updateKey, '$' ) > 1 ) {
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

		//inject or update inspection
		$insertResults = self::_runMongoActions( $mongoActions, 'Dispatch_insertEmbedded' );


		return $insertResults;
	}


	/**
	 * //run bulk write for mongo actions array
	 *
	 * @param  array   $mongoActions
	 * @param  string  $logChannel
	 *
	 * @return updateDeleteResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function _runMongoActions( array $mongoActions, string $logChannel ) : array {
		$logging = config::getEnvironmentConfig()->type=='local';

		$updateInsertDeleteResults = [];

		if(count($mongoActions)>0) {
			log::info( $logChannel, '-run queries ', $mongoActions  );
			try {
				$mdb     = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

				$session = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
				$session->startTransaction( [ 'maxCommitTimeMS' => 2000 ] );

				foreach( $mongoActions as $collectionName => $queries ) {
					$result = $mdb->db->$collectionName->bulkWrite( $queries, [ 'session' => $session ] );

					if($logging) {
						log::info( $logChannel, '---touch collection ' . $collectionName );
						log::info( $logChannel, '----Matched: ' . $result->getMatchedCount() );
						log::info( $logChannel, '----Mod: ' . $result->getModifiedCount() );
					}

					$updateInsertDeleteResults[] = new updateDeleteResult( $result );
				}

				$session->commitTransaction();
			}
			catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
				log::error( $logChannel, $e->getMessage(), $e->getTrace() );
				throw new \gcgov\framework\exceptions\modelException( 'Database error: ' . $e->getMessage(), 500, $e );
			}
		}

		return $updateInsertDeleteResults;

	}

	private static function convertFieldPathToComplexUpdate( string $fieldPath, bool $arrayFilter = true, string $arrayFilterKey = 'arrayFilter' ) : string {
		//convert $fieldPath  `
		// from     `inspections.$.scheduleRequests.$.comments.$`
		// to       `inspections.$[].scheduleRequests.$[].comments.$[arrayFilter]`
		$pathParts          = explode( '.', $fieldPath );
		$reversedPathParts  = array_reverse( $pathParts );
		$foundPrimaryTarget = false;
		foreach( $reversedPathParts as $i => $part ) {
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part === '$' ) {
				$foundPrimaryTarget = true;
				if( $arrayFilter ) {
					$reversedPathParts[ $i ] = '$[' . $arrayFilterKey . ']';
				}
				else {
					unset( $reversedPathParts[ $i ] );
				}
			}
			elseif( $foundPrimaryTarget && $part === '$' ) {
				$reversedPathParts[ $i ] = '$[]';
			}
		}
		$complexPathParts = array_reverse( $reversedPathParts );

		return implode( '.', $complexPathParts );
	}


	/**
	 * @return \gcgov\framework\services\mongodb\typeMap[]
	 */
	private static function getAllTypeMaps() : array {
		if( typeMapCache::allTypeMapsFetched() ) {
			return typeMapCache::getAll();
		}

		$appDir = config::getAppDir();

		//get app files
		$dir      = new \RecursiveDirectoryIterator( $appDir . '/models', \FilesystemIterator::SKIP_DOTS );
		$filter   = new \RecursiveCallbackFilterIterator( $dir, function( $current, $key, $iterator ) {
			if( $iterator->hasChildren() ) {
				return true;
			}
			elseif( $current->isFile() && 'php' === $current->getExtension() ) {
				return true;
			}

			return false;
		} );
		$fileList = new \RecursiveIteratorIterator( $filter );

		$allTypeMaps = [];

		/** @var \SplFileInfo $file */
		foreach( $fileList as $file ) {
			//convert file name to be the class name
			$namespace = trim( substr( $file->getPath(), strlen( config::getRootDir() ) ), '/\\' );
			$className = $file->getBasename( '.' . $file->getExtension() );
			$classFqn  = str_replace( '/', '\\', '\\' . $namespace . '\\' . $className );

			try {
				//load the class via reflection
				$classReflection = new \ReflectionClass( $classFqn );

				//check if this class is an instance of our model \gcgov\framework\services\mongodb\model
				if( $classReflection->isSubclassOf( \gcgov\framework\services\mongodb\model::class ) ) {
					$instance                                       = $classReflection->newInstanceWithoutConstructor();
					$allTypeMaps[ $instance->_getCollectionName() ] = $instance->_typeMap();
				}
			}
			catch( \ReflectionException $e ) {
				throw new \gcgov\framework\services\mongodb\exceptions\dispatchException( 'Reflection failed on class ' . $classFqn, 500, $e );
			}
		}

		typeMapCache::setAllTypeMapsFetched( true );

		return $allTypeMaps;
	}


	/**
	 * @param  string                  $collectionName
	 * @param  string                  $pathToUpdate
	 * @param  \MongoDB\BSON\ObjectId  $_id
	 *
	 * @return array[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	#[ArrayShape( [ 'updateMany' => "array" ] )]
	private static function _generateDeleteAction( string $collectionName, string $pathToUpdate, \MongoDB\BSON\ObjectId $_id ) : array {

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		$filter = [];

		$options = [
			'upsert'       => false
		];

		//check whether this is an array or nullable
		if( substr( $pathToUpdate, -1 ) === '$' ) {
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
//
//		try {
//			return $mdb->collection->updateMany( $filter, $update, $options );
//		}
//		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
//			log::error( 'Dispatch_deleteEmbedded', $e->getMessage(), $e->getTrace() );
//			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
//		}
	}


	/**
	 * @param  string  $collectionName
	 * @param  string  $pathToUpdate
	 * @param  object  $updateObject
	 *
	 * @return array
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	#[ArrayShape( [ 'updateMany' => "array" ] )]
	private static function _generateUpdateAction( string $collectionName, string $pathToUpdate, object $updateObject ) : array {

		//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
		$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

		$filter = [
			$filterPath . '._id' =>  $updateObject->_id
		];

		$options = [
			'upsert'       => false
		];

		$complex = self::buildUpdateKeyArrayFilters( $pathToUpdate, true, $updateObject->_id );

		//complex update
		$update = [
			'$set' => [
				$complex['complexPath'] => $updateObject
			]
		];

		$options[ 'arrayFilters' ] = $complex['arrayFilters'];

		return [ 'updateMany' => [ $filter, $update, $options ] ];

	}


	private static function convertFieldPathToFilterPath( string $fieldPath ) : string {
		return str_replace( '.$', '', $fieldPath );
	}


	#[ArrayShape( [ 'complexPath'  => "string",
	                'arrayFilters' => "array"
	] )]
	private static function buildUpdateKeyArrayFilters( string $fieldPath, bool $arrayFilter = true, mixed $arrayFilterValue=null ) : array {
		//convert $fieldPath  `
		// from     `inspections.$.scheduleRequests.$.comments.$`
		// to       `inspections.$[arrayFilter2].scheduleRequests.$[arrayFilter1].comments.$[arrayFilter0]`
		$pathParts          = explode( '.', $fieldPath );
		$reversedPathParts  = array_reverse( $pathParts );

		$arrayFilterIndex = 0;
		$arrayFilters = [];

		$previousParts = [];

		$foundPrimaryTarget = false;
		foreach( $reversedPathParts as $i => $part ) {
			$arrayFilterIndex = count($arrayFilters);
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part === '$' ) {
				$foundPrimaryTarget = true;
				if( $arrayFilter ) {
					$reversedPathParts[ $i ] = '$[arrayFilter'.$arrayFilterIndex.']';
					$arrayFilters[ $arrayFilterIndex ] = $previousParts;
				}
				else {
					unset( $reversedPathParts[ $i ] );
				}
			}
			elseif( $foundPrimaryTarget && $part === '$' ) {
				$reversedPathParts[ $i ] = '$[arrayFilter'.$arrayFilterIndex.']';
				$arrayFilters[ $arrayFilterIndex ] = $previousParts;
			}
			else {
				$previousParts[] = $part;
			}

		}
		$complexPathParts = array_reverse( $reversedPathParts );

		foreach($arrayFilters as $i=>$arrayFilter) {
			$arrayFilter[] = 'arrayFilter'.$i;
			$arrayFilters[$i] = [
				implode( '.', array_reverse($arrayFilter) ).'._id' => $arrayFilterValue
			];
		}

		return [
			'complexPath' => implode( '.', $complexPathParts ),
			'arrayFilters'=> array_reverse( $arrayFilters )
		];

	}

	private static function getFieldPathToFirstParentModel( string $startingFieldPath, typeMap $typeMap ) : string {
		if( substr_count( $startingFieldPath, '.' ) > 1 ) {
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