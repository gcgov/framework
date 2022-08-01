<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\models\_meta;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\attributes\autoIncrement;
use gcgov\framework\services\mongodb\models\audit;


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
	 * @param  array  $options    optional
	 *
	 * @return int
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function countDocuments( array $filter = [], array $options = [] ) : int {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		try {
			return $mdb->collection->countDocuments( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database counting collection '.static::_getCollectionName().' for filter: '.json_encode($filter).' with options: '.json_encode($options), 500, $e );
		}
	}

	/**
	 * @param  array  $filter  optional
	 * @param  array  $sort    optional
	 * @param  array  $options    optional
	 *
	 * @return array
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getAll( array $filter = [], array $sort = [], array $options = [] ) : array {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$options = array_merge( $options, [
			'typeMap' => static::_getTypeMap()
		] );
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
	 * @param  array  $filter  optional
	 * @param  array  $options    optional
	 *
	 * @return object
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOneBy( array $filter = [], array $options = []  ) : object {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$options = array_merge( $options, [
			'typeMap' => static::_getTypeMap()
		] );

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

		//AUDIT CHANGE STREAM
		if( $mdb->audit && static::_getCollectionName() != 'audit' ) {
			$auditChangeStream = new audit();
			$auditChangeStream->startChangeStreamWatch( $mdb->collection );
		}

		//ACTUAL UPDATE
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
			log::info( 'MongoService', 'Save ' . $object::class );
			log::info( 'MongoService', '--Matched:  ' . $updateResult->getMatchedCount() );
			log::info( 'MongoService', '--Modified: ' . $updateResult->getModifiedCount() );
			log::info( 'MongoService', '--Upserted: ' . $updateResult->getUpsertedCount() );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error. ' . $e->getMessage(), 500, $e );
		}


		//auto increment fields on insert
		if( $updateResult->getUpsertedCount() > 0 ) {
			$autoIncrementUpdateResult = static::autoIncrementProperties( $object );
		}

		//dispatch inserts for all embedded versions
		$embeddedInserts = static::_insertEmbedded( $object );

		//dispatch updates for all embedded versions
		$embeddedUpdates = static::_updateEmbedded( $object );

		$combinedResult = new updateDeleteResult( $updateResult, array_merge( $embeddedUpdates, $embeddedInserts ?? [] ) );

		//update _meta property of object to show results
		if( property_exists( $object, '_meta' )  ) {
			if( !isset($object->_meta)) {
				$object->_meta = new _meta( get_called_class() );
			}
			$object->_meta->setDb( $combinedResult );
		}

		//AUDIT CHANGE STREAM
		if( $mdb->audit && static::_getCollectionName() != 'audit' && ($combinedResult->getUpsertedCount()>0 || $combinedResult->getModifiedCount()>0)) {
			$auditChangeStream->processChangeStream( $combinedResult );
			audit::save( $auditChangeStream );
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

		//AUDIT CHANGE STREAM
		if( $mdb->audit && static::_getCollectionName() != 'audit' ) {
			$auditChangeStream = new audit();
			$auditChangeStream->startChangeStreamWatch( $mdb->collection );
		}

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		log::info( 'MongoService', 'Delete ' . static::_getCollectionName() );

		$objectToDelete       = static::getOne( $_id );
		$deleteCascadeResults = self::_deleteCascade( $objectToDelete );

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
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//dispatch delete for all embedded versions
		$embeddedDeletes = self::_deleteEmbedded( static::_typeMap()->root, $_id );

		//combine primary delete with embedded
		$combinedResult = new updateDeleteResult( $deleteResult, $embeddedDeletes );

		if( $combinedResult->getEmbeddedDeletedCount() + $combinedResult->getDeletedCount() + $combinedResult->getModifiedCount() + $combinedResult->getEmbeddedModifiedCount() == 0 ) {
			throw new modelException( static::_getHumanName( capitalize: true ) . ' not deleted because it was not found', 404 );
		}

		//AUDIT CHANGE STREAM
		if( $mdb->audit && static::_getCollectionName() != 'audit' && $combinedResult->getDeletedCount()>0) {
			$auditChangeStream->processChangeStream( $combinedResult );
			audit::save( $auditChangeStream );
		}

		return $combinedResult;
	}


	/**
	 * @param  \gcgov\framework\services\mongodb\model  $object
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function autoIncrementProperties( model $object ) : updateDeleteResult {
		log::info( 'MongoServiceAutoIncrement', 'Start auto increment ' . $object::class );

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
					$autoIncrementAttributes[ $property->getName() ] = $attributes[ 0 ]->newInstance();
				}
			}
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoServiceAutoIncrement', 'Auto increment reflection error ' . $object::class );
			error_log( $e );

			return new updateDeleteResult();
		}

		//if no properties are auto increments, do nothing
		if( count( $autoIncrementAttributes ) === 0 ) {
			return new updateDeleteResult();
		}

		//do the auto incrementing

		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$session = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );

		$updateResult = null;
		$session->startTransaction( [ 'maxCommitTimeMS' => 2000 ] );
		try {
			$mongodbSet = [];

			foreach( $autoIncrementAttributes as $propertyName => $autoIncrement ) {
				//create key for auto increment group
				$key = static::_getCollectionName() . '.' . $propertyName;
				if( $autoIncrement->groupByPropertyName !== '' ) {
					$key .= '.' . $object->{$autoIncrement->groupByPropertyName};
				}
				if( $autoIncrement->groupByMethodName !== '' ) {
					$key .= '.' . $object->{$autoIncrement->groupByMethodName}();
				}
				log::info( 'MongoServiceAutoIncrement', '--' . $key );

				//get and increment internalCounter
				$internalCounter = \gcgov\framework\services\mongodb\models\internalCounter::getAndIncrement( $key, $session );

				//set the new count on the object (by ref)
				//if the value is supposed to be formatted
				if( $autoIncrement->countFormatMethod !== '' ) {
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
			log::error( 'MongoServiceAutoIncrement', '--' . $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error: ' . $e->getMessage(), 500, $e );
		}

		//close the session
		$session->endSession();

		log::info( 'MongoServiceAutoIncrement', '--Matched: ' . $updateResult->getMatchedCount() );
		log::info( 'MongoServiceAutoIncrement', '--Modified: ' . $updateResult->getModifiedCount() );
		log::info( 'MongoServiceAutoIncrement', '--Upserted: ' . $updateResult->getUpsertedCount() );

		return new updateDeleteResult( $updateResult );
	}

}