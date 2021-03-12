<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\config;


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
		error_log( 'Dispatch _updateEmbedded for '.$object::class );
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
					error_log( '--update collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $updateType );
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
		error_log( 'Dispatch _deleteEmbedded' );
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
					error_log( '-- delete item on collection ' . $collectionName . ' root type ' . $typeMap->root . ' key ' . $fieldKey . ' type ' . $deleteType );
					$embeddedDeletes[] = self::_doDelete( $collectionName, $fieldKey, $_id );
				}
			}
		}

		return $embeddedDeletes;
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
					$complexPath => ['_id'=>$_id]
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
			error_log( $e );
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

		//TODO: filter here? I'm matching on all documents so in the function response, it shows more matched than modified
		$filter = [];

		$options = [
			'upsert'       => false,
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		//determine update type (is this object inside more than one array?
		if( substr_count( $pathToUpdate, '$' ) > 1 ) {

			$arrayFilterKey = 'arrayFilter';
			$complexPath = self::convertFieldPathToComplexUpdate( $pathToUpdate, true, 'arrayFilter');

			//complex update
			$update = [
				'$set' => [
					$complexPath => $updateObject
				]
			];

			$options[ 'arrayFilters' ] = [
				[ $arrayFilterKey.'._id' => $updateObject->_id ]
			];
		}
		else {
			//simple update

			//drop all dollar signs from the filter path (for some reason Mongo demands none in the filter)
			$filterPath = self::convertFieldPathToFilterPath( $pathToUpdate );

			$filter[ $filterPath . '._id' ] = $updateObject->_id;

			$update = [
				'$set' => [
					$pathToUpdate => $updateObject
				]
			];
		}

		try {
			$updateResponse = $mdb->collection->updateMany( $filter, $update, $options );
			error_log( '----Matched: ' . $updateResponse->getMatchedCount() );
			error_log( '----Mod: ' . $updateResponse->getModifiedCount() );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log( $e );
			throw new \gcgov\framework\exceptions\modelException( 'Database error while updating ' . $pathToUpdate, 500, $e );
		}

		return $updateResponse;
	}

	private static function convertFieldPathToFilterPath( string $fieldPath ) : string {
		return str_replace( '.$', '', $fieldPath );
	}

	private static function convertFieldPathToComplexUpdate( string $fieldPath, bool $arrayFilter=true, string $arrayFilterKey='arrayFilter' ) : string {
		//convert $fieldPath  `
			// from     `inspections.$.scheduleRequests.$.comments.$`
			// to       `inspections.$[].scheduleRequests.$[].comments.$[arrayFilter]`
		$pathParts          = explode( '.', $fieldPath );
		$reversedPathParts  = array_reverse( $pathParts );
		$foundPrimaryTarget = false;
		foreach( $reversedPathParts as $i => $part ) {
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part === '$' ) {
				$foundPrimaryTarget      = true;
				if($arrayFilter) {
					$reversedPathParts[ $i ] = '$['.$arrayFilterKey.']';
				}
				else {
					unset($reversedPathParts[$i]);
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

}