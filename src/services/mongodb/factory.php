<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\attributes\autoIncrement;
use gcgov\framework\services\mongodb\attributes\saveChildOnSave;


/**
 * Standard factory methods provided for all models
 * @package gcgov\framework\services\mongodb
 */
abstract class factory
	extends
	dispatcher {

	abstract static function _getCollectionName() : string;


	abstract static function _getTypeMap() : array;


	abstract static function _getHumanName( bool $capitalize = false, bool $plural = false ) : string;


	/**
	 * @param  array  $filter  optional
	 * @param  array  $sort    optional
	 *
	 * @return array
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getAll( array $filter = [], array $sort = [] ) : array {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$options = [
			'typeMap' => static::_getTypeMap()
		];
		if( count( $sort ) > 0 ) {
			$options[ 'sort' ] = $sort;
		}

		try {
			$cursor = $mdb->collection->find( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		return $cursor->toArray();
	}


	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 *
	 * @return object
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOne( \MongoDB\BSON\ObjectId|string $_id ) : object {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		$filter = [
			'_id' => $_id
		];

		$options = [
			'typeMap' => static::_getTypeMap(),
		];

		try {
			$cursor = $mdb->collection->findOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		if( $cursor === null ) {
			throw new \gcgov\framework\exceptions\modelException( static::_getHumanName( capitalize: true ) . ' not found', 404 );
		}

		return $cursor;
	}


	/**
	 * @param        $object
	 * @param  bool  $upsert
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function save( &$object, bool $upsert = true ) : updateDeleteResult {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$filter = [
			'_id' => $object->_id
		];

		$update = [
			'$set' => $object
		];

		$options = [
			'upsert'       => $upsert,
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		try {
			$updateResult = $mdb->collection->updateOne( $filter, $update, $options );
			error_log("Save ".$object::class."\nMatched: ".$updateResult->getMatchedCount()."\nModified: ".$updateResult->getModifiedCount()."\nUpserted:".$updateResult->getUpsertedCount());
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log( $e );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//auto increment fields on insert
		if( $updateResult->getUpsertedCount() > 0 ) {
			$autoIncrementUpdateResult = static::autoIncrementProperties( $object );
		}

		//dispatch updates for all embedded versions
		$embeddedUpdates = static::_updateEmbedded( $object );

		//save changes to children where attributed to make updates
		$childrenUpdates = static::saveChildren( $object );
		//since the child may be nested in other properties on the object, we have to refresh the object itself after making the children updates
		if(count($childrenUpdates)>0) {
			foreach($childrenUpdates as $childUpdate) {
				if($childUpdate->getModifiedCount()>0 || $childUpdate->getUpsertedCount()>0 || $childUpdate->getDeletedCount()>0 || $childUpdate->getEmbeddedModifiedCount()>0 || $childUpdate->getEmbeddedUpsertedCount()>0 || $childUpdate->getEmbeddedDeletedCount()>0) {
					$object = self::getOne( $object->_id );
					break;
				}
			}
		}


		$combinedResult = new updateDeleteResult( $updateResult, $embeddedUpdates, $childrenUpdates );

		//update _meta property of object to show results
		if( property_exists( $object, '_meta' ) ) {
			$object->_meta->setDb( $combinedResult );
		}

		return $combinedResult;
	}


	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function delete( \MongoDB\BSON\ObjectId|string $_id ) : updateDeleteResult {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		$filter = [
			'_id' => $_id
		];

		$options = [
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		try {
			$deleteResult = $mdb->collection->deleteOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log( $e );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//dispatch delete for all embedded versions
		$embeddedDeletes = self::_deleteEmbedded( static::_typeMap()->root, $_id );

		//combine primary delete with embedded
		$combinedResult = new updateDeleteResult( $deleteResult, $embeddedDeletes );

		if( $combinedResult->getEmbeddedDeletedCount() + $combinedResult->getDeletedCount() + $combinedResult->getModifiedCount() + $combinedResult->getEmbeddedModifiedCount() == 0 ) {
			throw new modelException( static::_getHumanName( capitalize: true ) . ' not deleted because it was not found', 404 );
		}

		return $combinedResult;
	}


	/**
	 * @param $object
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 */
	private static function saveChildren( &$object ) : array {
		error_log('Save children for '.$object::class);

		$updateResults = [];

		//find fields marked with autoIncrement attribute on $object (must be of type _model)
		try {
			$reflectionClass = new \ReflectionClass( $object );

			foreach( $reflectionClass->getProperties() as $property ) {
				$attributes = $property->getAttributes( saveChildOnSave::class );

				//this is an save child field
				if( count( $attributes ) > 0 ) {
					/** @var attributes\saveChildOnSave $saveChildOnSaveAttributes */
					$attribute = $attributes[0]->newInstance();

					//get property type
					$rPropertyType = $property->getType();
					$typeName      = $rPropertyType->getName();
					$typeIsArray   = false;

					//handle typed arrays
					if( $typeName == 'array' ) {
						//get type  from @var doc block
						$typeName    = typeHelpers::getVarTypeFromDocComment( $property->getDocComment() );
						$typeIsArray = true;
					}

					//make sure it has save
					if(!$typeIsArray) {
						$items = [ &$object->{$property->getName()} ];
					}
					else {
						$items = &$object->{$property->getName()};
					}

					error_log('---- '.$object::class.'->'.$property->getName());

					foreach($items as $i=>$item) {
						$rPropertyClass = new \ReflectionClass( $typeName );
						if( $rPropertyClass->isSubclassOf( model::class ) ) {
							$updateResults[] = $rPropertyClass->getMethod( 'save')->invokeArgs( $items[$i], array( &$items[$i] ) );
						}
						elseif( $rPropertyClass->isSubclassOf( embeddable::class ) ) {
							$updateResults = array_merge( $updateResults, self::saveChildren( $items[$i] ) );
						}
					}

				}
			}
		}
		catch( \ReflectionException $e ) {
			error_log($e);
		}

		return $updateResults;

	}


	/**
	 * @param  \gcgov\framework\services\mongodb\model  $object
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function autoIncrementProperties( model $object ) : updateDeleteResult {

		/** @var attributes\autoIncrement[] $autoIncrementAttributes */
		$autoIncrementAttributes = [];

		//find fields marked with autoIncrement attribute on $object (must be of type _model)
		try {
			$reflectionClass = new \ReflectionClass( $object );

			foreach( $reflectionClass->getProperties() as $property ) {
				$attributes = $property->getAttributes( autoIncrement::class );

				//this is an auto increment field
				if( count( $attributes ) > 0 ) {
					/** @var attributes\autoIncrement $autoIncrement */
					$autoIncrementAttributes[ $property->getName() ] =  $attributes[0]->newInstance();
				}
			}
		}
		catch( \ReflectionException $e ) {
			error_log($e);
			return new updateDeleteResult();
		}

		//if no properties are auto increments, do nothing
		if( count( $autoIncrementAttributes ) === 0 ) {
			return new updateDeleteResult();
		}

		//do the auto incrementing
		error_log('Auto increment');

		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$session = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );

		$updateResult = null;
		$session->startTransaction( [ 'maxCommitTimeMS' => 2000 ] );
		try {
			$mongodbSet = [];

			foreach( $autoIncrementAttributes as $propertyName=>$autoIncrement ) {

				//create key for auto increment group
				$key = static::_getCollectionName() . '.' . $propertyName;
				if($autoIncrement->groupByPropertyName!=='') {
					$key .= '.'. $object->{$autoIncrement->groupByPropertyName};
				}
				if($autoIncrement->groupByMethodName!=='') {
					$key .= '.'. $object->{$autoIncrement->groupByMethodName}();
				}
				error_log($key);

				//get and increment internalCounter
				$internalCounter = \gcgov\framework\services\mongodb\models\internalCounter::getAndIncrement( $key, $session );

				//set the new count on the object (by ref)
				//if the value is supposed to be formatted
				if($autoIncrement->countFormatMethod!=='') {
					$object->{$propertyName} = $object->{$autoIncrement->countFormatMethod}( $internalCounter->currentCount );
				}
				else {
					$object->{$propertyName} = $internalCounter->currentCount;
				}

				//set the new count on the mongo db set operator
				$mongodbSet[ $propertyName ] = $object->{$propertyName};
			}

			//update the transaction batch
			$filter = [
				'_id' => $object->_id
			];

			$update = [
				'$set' => $mongodbSet
			];

			$options      = [
				'upsert'  => true,
				'session' => $session
			];
			$updateResult = $mdb->collection->updateOne( $filter, $update, $options );

			$session->commitTransaction();
		}
		catch( \MongoDB\Driver\Exception\RuntimeException | \MongoDB\Driver\Exception\CommandException $e ) {
			$session->abortTransaction();
			throw new \gcgov\framework\exceptions\modelException( 'Database error: ' . $e->getMessage(), 500, $e );
		}

		//close the session
		$session->endSession();

		error_log('Matched: '.$updateResult->getMatchedCount());
		error_log('Modified: '.$updateResult->getModifiedCount());
		error_log('Upserted: '.$updateResult->getUpsertedCount());

		return new updateDeleteResult( $updateResult );
	}

}