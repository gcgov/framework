<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\log;
use gcgov\framework\services\mongodb\attributes\deleteCascade;
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
		log::info( 'Dispatch_updateEmbedded', 'Start _updateEmbedded for ' . $object::class );
		$embeddedUpdates = [];

		//the type of object we are updating
		$updateType = '\\' . trim( get_class( $object ), '/\\' );

		//get all model typemaps
		$allTypeMaps = self::getAllTypeMaps();

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $collectionName => $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths ) === 0 || $typeMap->root == $updateType ) {
				continue;
			}

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $updateType == $fieldPath ) {
					log::info( 'Dispatch_updateEmbedded', '--update collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $updateType );
					//TODO: change this to a transaction bulk write

					//

					$embeddedUpdates[] = self::_doUpdate( $collectionName, $fieldKey, $object );
				}
			}
		}

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
		log::info( 'Dispatch_deleteEmbedded', 'Start _deleteEmbedded for ' . $deleteType );
		$embeddedDeletes = [];

		//the type of object we are updating
		$deleteType = '\\' . trim( $deleteType, '/\\' );

		//get all model typemaps
		$allTypeMaps = self::getAllTypeMaps();

		//find all places this type is embedded and update the instance
		foreach( $allTypeMaps as $collectionName => $typeMap ) {
			//don't check if there are no embedded objects OR the type that we're sending an update for
			if( count( $typeMap->fieldPaths ) === 0 || $typeMap->root == $deleteType ) {
				continue;
			}

			//check if updateType is embedded in this typeMap
			foreach( $typeMap->fieldPaths as $fieldKey => $fieldPath ) {
				if( $deleteType == $fieldPath ) {
					log::info( 'Dispatch_deleteEmbedded', '-- delete item on collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $deleteType );
					//TODO: change this to a transaction bulk write
					$embeddedDeletes[] = self::_doDelete( $collectionName, $fieldKey, $_id );
				}
			}
		}

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
					/** @var attributes\autoIncrement $autoIncrement */
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
				$deleteResponses = $objectTypeToDelete::delete( $item->_id );
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
		log::info( 'Dispatch_insertEmbedded', 'Start _insertEmbedded for ' . $objectToInsert::class );

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
		$mdb     = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );
		$session = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
		$session->startTransaction( [ 'maxCommitTimeMS' => 2000 ] );

		$insertResults = [];

		try {
			foreach( $mongoActions as $collectionName => $queries ) {
				log::info( 'Dispatch_insertEmbedded', '--insert into ' . $collectionName . ' ' . json_encode( $queries ) );

				//bulk actions for this collection
				if( count( $queries ) > 1 ) {
					$result = $mdb->db->$collectionName->bulkWrite( $queries, [ 'session' => $session ] );
				}
				//single insert for the collection
				elseif( count( $queries ) == 1 ) {
					$q       = $queries[ 0 ][ 'updateOne' ];
					$options = array_merge( $q[ 2 ], [
						'upsert'  => false,
						'session' => $session
					] );
					$result  = $mdb->db->$collectionName->updateOne( $q[ 0 ], $q[ 1 ], $options );
				}
				else {
					continue;
				}

				log::info( 'Dispatch_insertEmbedded', '----Matched: ' . $result->getMatchedCount() );
				log::info( 'Dispatch_insertEmbedded', '----Mod: ' . $result->getModifiedCount() );

				$insertResults[] = new updateDeleteResult( $result );
			}

			$session->commitTransaction();
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			log::error( 'Dispatch_insertEmbedded', $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error: ' . $e->getMessage(), 500, $e );
		}

		return $insertResults;
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
		$appDir = config::getAppDir();

		//get app files
		$dir      = new \RecursiveDirectoryIterator( $appDir . '/models', \RecursiveDirectoryIterator::SKIP_DOTS );
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

		return $allTypeMaps;
	}


	/**
	 * @param  string                  $collectionName
	 * @param  string                  $pathToUpdate
	 * @param  \MongoDB\BSON\ObjectId  $_id
	 *
	 * @return \MongoDB\UpdateResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function _doDelete( string $collectionName, string $pathToUpdate, \MongoDB\BSON\ObjectId $_id ) : \MongoDB\UpdateResult {
		$mdb = new tools\mdb( collection: $collectionName );

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		$filter = [];

		$options = [
			'upsert'       => false,
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
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

		try {
			return $mdb->collection->updateMany( $filter, $update, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			log::error( 'Dispatch_deleteEmbedded', $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}
	}


	/**
	 * @param  string  $collectionName
	 * @param  string  $pathToUpdate
	 * @param  object  $updateObject
	 *
	 * @return \MongoDB\UpdateResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function _doUpdate( string $collectionName, string $pathToUpdate, object $updateObject ) : \MongoDB\UpdateResult {
		$mdb = new tools\mdb( collection: $collectionName );

		//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
		$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

		$filter = [
			$filterPath . '._id' =>  $updateObject->_id
		];

		$options = [
			'upsert'       => false,
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		//determine update type (is this object inside more than one array?
		if( substr_count( $pathToUpdate, '$' ) > 1 ) {
			$complex = self::buildUpdateKeyArrayFilters( $pathToUpdate, true, $updateObject->_id );

			//complex update
			$update = [
				'$set' => [
					$complex['complexPath'] => $updateObject
				]
			];

			$options[ 'arrayFilters' ] = $complex['arrayFilters'];
		}
		else {
			//simple update
			$update = [
				'$set' => [
					$pathToUpdate => $updateObject
				]
			];
		}

		try {
			$updateResponse = $mdb->collection->updateMany( $filter, $update, $options );
			log::info( 'Dispatch_updateEmbedded', '----'.json_encode( $filter ) );
			log::info( 'Dispatch_updateEmbedded', '----'.json_encode( $update ) );
			log::info( 'Dispatch_updateEmbedded', '----Matched: ' . $updateResponse->getMatchedCount() );
			log::info( 'Dispatch_updateEmbedded', '----Mod: ' . $updateResponse->getModifiedCount() );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			log::error( 'Dispatch_updateEmbedded', $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error while updating ' . $pathToUpdate, 500, $e );
		}

		return $updateResponse;
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