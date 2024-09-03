<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\exceptions\modelDocumentNotFoundException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\attributes\autoIncrement;
use gcgov\framework\services\mongodb\models\_meta;
use gcgov\framework\services\mongodb\tools\auditManager;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\services\mongodb\tools\reflectionCache;
use gcgov\framework\services\mongodb\tools\sys;

/**
 * Standard factory methods provided for all models
 * @package gcgov\framework\services\mongodb
 */
abstract class factory
	extends dispatcher {

	abstract static function _getCollectionName(): string;


	abstract static function _getHumanName( bool $capitalize = false, bool $plural = false ): string;


	/**
	 * @param array $filter  optional
	 * @param array $options optional
	 *
	 * @return int
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function countDocuments( array $filter = [], array $options = [] ): int {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		try {
			return $mdb->collection->countDocuments( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database counting collection ' . static::_getCollectionName() . ' for filter: ' . json_encode( $filter ) . ' with options: ' . json_encode( $options ), 500, $e );
		}
	}


	/**
	 * @param array $filter  optional
	 * @param array $sort    optional
	 * @param array $options optional
	 *
	 * @return $this[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getAll( array $filter = [], array $sort = [], array $options = [], ?\MongoDB\Driver\Session $mongoDbSession = null ): array {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$options = array_merge( $options, [
			'typeMap' => static::getBsonOptionsTypeMap( typeMapType::unserialize )
		] );
		if(isset($mongoDbSession)) {
			$options['session'] = $mongoDbSession;
		}
		if( count( $sort )>0 ) {
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
	 * @param int|string|null $limit
	 * @param int|string|null $page
	 * @param array           $filter  optional
	 * @param array           $options optional
	 *
	 * @return \gcgov\framework\services\mongodb\getResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getPagedResponse( int|string|null $limit, int|string|null $page, array $filter = [], array $options = [], ?\MongoDB\Driver\Session $mongoDbSession = null ): getResult {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$result = new getResult( $limit, $page );

		$options            = array_merge( $options, [
			'typeMap' => static::getBsonOptionsTypeMap( typeMapType::unserialize )
		] );
		$options[ 'limit' ] = $result->getLimit();
		$options[ 'skip' ]  = ( $result->getPage() - 1 ) * $result->getLimit();
		if(isset($mongoDbSession)) {
			$options['session'] = $mongoDbSession;
		}

		try {
			$cursor = $mdb->collection->find( $filter, $options );
			$result->setData( $cursor->toArray() );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		$result->setTotalDocumentCount( self::countDocuments( $filter ) );

		return $result;
	}


	/**
	 * @param \MongoDB\BSON\ObjectId|string $_id
	 * @param \MongoDB\Driver\Session|null  $mongoDbSession
	 *
	 * @return $this
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOne( \MongoDB\BSON\ObjectId|string $_id, ?\MongoDB\Driver\Session $mongoDbSession = null ): object {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		$filter = [
			'_id' => $_id
		];

		$options = [
			'typeMap' => static::getBsonOptionsTypeMap( typeMapType::unserialize ),
		];
		if(isset($mongoDbSession)) {
			$options['session'] = $mongoDbSession;
		}

		try {
			$cursor = $mdb->collection->findOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		if( $cursor===null ) {
			throw new \gcgov\framework\exceptions\modelException( static::_getHumanName( capitalize: true ) . ' not found', 404 );
		}

		return $cursor;
	}


	/**
	 * @param array $filter  optional
	 * @param array $options optional
	 *
	 * @return $this
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOneBy( array $filter = [], array $options = [], ?\MongoDB\Driver\Session $mongoDbSession = null ): object {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		$options = array_merge( $options, [
			'typeMap' => static::getBsonOptionsTypeMap( typeMapType::unserialize )
		] );
		if(isset($mongoDbSession)) {
			$options['session'] = $mongoDbSession;
		}

		try {
			$cursor = $mdb->collection->findOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		if( $cursor===null ) {
			throw new \gcgov\framework\exceptions\modelException( static::_getHumanName( capitalize: true ) . ' not found', 404 );
		}

		return $cursor;
	}


	/**
	 * @param array                        $objects
	 * @param bool                         $upsert
	 * @param bool                         $callBeforeAfterHooks
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function saveMany( array &$objects, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null ): array {
		$calledClass = get_called_class();

		log::info( 'MongoService', 'Save '.count($objects).' ' . $calledClass );

		$mdb         = new tools\mdb( collection: static::_getCollectionName() );

		//call _beforeSave for each item
		if( $callBeforeAfterHooks && method_exists( $calledClass, '_beforeSave' ) ) {
			log::info( 'MongoService', '--call _beforeSave' );
			foreach( $objects as &$object ) {
				static::_beforeSave( $object );
			}
		}
		unset( $object );

		//AUDIT CHANGE STREAM
		/*$auditManager = new auditManager($mdb);
		if( $mdb->audit && static::_getCollectionName()!='audit' ) {
			$auditManager->startChangeStreamWatchOnDb();
		}*/

		//open database session if one is not already open
		log::info( 'MongoService', '--do save' );
		$sessionParent = false;
		if( !isset( $mongoDbSession ) ) {
			$sessionParent  = true;
			$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
			$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 60000 ] );
		}

		/** @var \gcgov\framework\services\mongodb\updateDeleteResult[] $saveResults */
		$saveResults = [];

		/** @var \gcgov\framework\services\mongodb\updateDeleteResult[] $autoIncrementUpdateResult */
		$autoIncrementUpdateResults = [];

		$mongoActions = [];

		try {
			foreach( $objects as $objectIndex => &$object ) {
				$saveResults[ $objectIndex ] = self::_saveItem( $object, $upsert, $mdb, $mongoDbSession );

				//auto increment fields on insert
				if( $saveResults[ $objectIndex ]->getUpsertedCount()>0 ) {
					$autoIncrementUpdateResults[] = static::autoIncrementProperties( $object, $mongoDbSession );
				}

				//dispatch inserts for all embedded versions
				$insertEmbeddedActions = static::_getInsertEmbeddedMongoActions( $object );
				$mongoActions = array_merge_recursive($mongoActions, $insertEmbeddedActions );

				//dispatch updates for all embedded versions
				$updateEmbeddedActions = static::_getUpdateEmbeddedMongoActions( $object );
				$mongoActions = array_merge_recursive($mongoActions, $updateEmbeddedActions );

				if( sys::propertyExists( get_called_class(), '_meta' ) ) {
					if( !isset( $object->_meta ) ) {
						$object->_meta = new _meta( get_called_class() );
					}
					$object->_meta->setDb( new updateDeleteResult( $saveResults[ $objectIndex ] ) );
				}
			}
			unset( $object );

			//run embedded actions
			$embeddedResults = self::_runMongoActions( $mongoActions, 'save many embedded', $mongoDbSession );

			//commit session
			if( $sessionParent ) {
				$mongoDbSession->commitTransaction();
			}
		}
		catch( modelException|\MongoDB\Driver\Exception\RuntimeException|\MongoDB\Driver\Exception\CommandException $e ) {
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}

			//call _afterSave for each item with an unsuccessful save
			if( $callBeforeAfterHooks && sys::methodExists( $calledClass, '_afterSave' ) ) {
				log::info( 'MongoService', '--call _afterSave' );
				foreach( $objects as &$object ) {
					static::_afterSave( $object, false );
				}
			}

			log::error( 'MongoService', '--Save many commit transaction failed: ' . $e->getMessage());
			throw new \gcgov\framework\exceptions\modelException( 'Storing ' . static::_getCollectionName() . ' in the database failed', 500, $e );
		}

		//AUDIT CHANGE STREAM
		$combinedResult = new updateDeleteResult( $saveResults, array_merge( $autoIncrementUpdateResults, $embeddedResults ) );

		/*if( $mdb->audit && static::_getCollectionName()!='audit' && ( $combinedResult->getUpsertedCount()>0 || $combinedResult->getModifiedCount()>0 || $combinedResult->getDeletedCount()>0 ) ) {
			$auditEntries = $auditManager->processChangeStream( $combinedResult );
			if( count( $auditEntries )>0 ) {
				try {
					audit::saveMany( $auditEntries );
				}
				catch( modelException $e ) {
					log::error( 'MongoService', 'Failed to save audit log entries' . $e->getMessage() );
				}
			}
		}*/

		//call _afterSave for each item with a successful save
		if( $callBeforeAfterHooks && sys::methodExists( $calledClass, '_afterSave' ) ) {
			foreach( $objects as $objectIndex => &$object ) {
				static::_afterSave( $object, true, new updateDeleteResult($saveResults[ $objectIndex ]) );
			}
			unset( $object );
		}

		return array_values( $saveResults );
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function _saveItem( object &$object, bool $upsert, tools\mdb $mdb, \MongoDB\Driver\Session $mongoDbSession  ): \MongoDB\UpdateResult {

		//ACTUAL UPDATE
		$filter = [
			'_id' => $object->_id
		];

		$update = [
			'$set' => $object
		];

		$options = [
			'upsert'  => $upsert,
			'session' => $mongoDbSession
		];

		try {
			return $mdb->collection->updateOne( $filter, $update, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			log::error( 'MongoService', '--_saveItem failed: ' . $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Storing ' . $object::class . ' in the database failed', 500, $e );
		}

	}


	/**
	 * @param object                       $object
	 * @param bool                         $upsert
	 * @param bool                         $callBeforeAfterHooks
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function save( object &$object, bool $upsert = true, bool $callBeforeAfterHooks = true, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult {
		if( $callBeforeAfterHooks && method_exists( get_called_class(), '_beforeSave' ) ) {
			static::_beforeSave( $object );
		}

		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		//AUDIT CHANGE STREAM
		$auditManager = new auditManager($mdb);
		$previousObject = null;

		//open database session if one is not already open
		$sessionParent = false;
		if( !isset( $mongoDbSession ) ) {
			$sessionParent  = true;
			$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
			$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 5000 ] );
		}

		//ACTUAL UPDATE
		try {
			if($auditManager->doAudit) {
				$previousObject = static::getOne( $object->_id );
			}

			$updateResult = static::_saveItem( $object, $upsert, $mdb, $mongoDbSession );

			//auto increment fields on insert
			if( $updateResult->getUpsertedCount()>0 ) {
				$autoIncrementUpdateResult = static::autoIncrementProperties( $object, $mongoDbSession );
			}

			//dispatch inserts for all embedded versions
			$embeddedInserts = static::_insertEmbedded( $object, $mongoDbSession );

			//dispatch updates for all embedded versions
			$embeddedUpdates = static::_updateEmbedded( $object, $mongoDbSession );

			//commit session
			if( $sessionParent ) {
				$mongoDbSession->commitTransaction();
			}
		}
		catch( \MongoDB\Driver\Exception\RuntimeException|modelException $e ) {
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}

			if( $callBeforeAfterHooks && sys::methodExists( get_called_class(), '_afterSave' ) ) {
				static::_afterSave( $object, false );
			}

			log::error( 'MongoService', '--Commit transaction failed: ' . $e->getMessage(), $e->getTrace() );
			throw new \gcgov\framework\exceptions\modelException( 'Storing ' . $object::class . ' in the database failed', 500, $e );
		}

		$combinedResult = new updateDeleteResult( $updateResult, array_merge( isset( $autoIncrementUpdateResult ) ? [ $autoIncrementUpdateResult ] : [], $embeddedUpdates, $embeddedInserts ) );

		//update _meta property of object to show results
		if( sys::propertyExists( get_called_class(), '_meta' ) ) {
			if( !isset( $object->_meta ) ) {
				$object->_meta = new _meta( get_called_class() );
			}
			$object->_meta->setDb( $combinedResult );
		}

		if( $sessionParent && $mongoDbSession->isInTransaction() ) {
			$mongoDbSession->endSession();
		}

		//AUDIT CHANGE STREAM
		if($auditManager->doAudit) {
			$auditManager->recordDiff( $object, $previousObject, $combinedResult );
		}

		if( $callBeforeAfterHooks && sys::methodExists( get_called_class(), '_afterSave' ) ) {
			static::_afterSave( $object, true, $combinedResult );
		}

		return $combinedResult;
	}


	/**
	 * @param \MongoDB\BSON\ObjectId|string $_id
	 * @param \MongoDB\Driver\Session|null  $mongoDbSession
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelDocumentNotFoundException
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function delete( \MongoDB\BSON\ObjectId|string $_id, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		//AUDIT CHANGE STREAM
		/*$auditManager = new auditManager($mdb);
		if( $mdb->audit && static::_getCollectionName()!='audit' ) {
			$auditManager->startChangeStreamWatchOnDb();
		}*/

		$_id = \gcgov\framework\services\mongodb\tools\helpers::stringToObjectId( $_id );

		log::info( 'MongoService', 'Delete ' . static::_getCollectionName() );

		//open database session if one is not already open
		$sessionParent = false;
		if( !isset( $mongoDbSession ) ) {
			$sessionParent  = true;
			$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
			$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 5000 ] );
		}

		try {
			$objectToDelete = static::getOne( $_id, $mongoDbSession );
		}
		catch( modelException $e ) {
			log::info( 'MongoService', '--object '.$_id.' does not exist in collection ' . static::_getCollectionName() );
		}

		//
		if(isset($objectToDelete)) {
			try {
				$deleteCascadeResults = self::_deleteCascade( $objectToDelete, $mongoDbSession );
			}
			catch( modelException $e ) {
				log::info( 'MongoService', '--_deleteCascade failed for object '.$_id.' ' . static::_getCollectionName() );
			}
		}

		$filter = [
			'_id' => $_id
		];

		$options = [
			'session' => $mongoDbSession
		];

		try {
			$deleteResult = $mdb->collection->deleteOne( $filter, $options );

			//commit session
			if( $sessionParent ) {
				$mongoDbSession->commitTransaction();
			}
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			//commit session
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//dispatch delete for all embedded versions
		$embeddedDeletes = self::_deleteEmbedded( get_called_class(), $_id, $mongoDbSession );

		//combine primary delete with embedded
		$combinedResult = new updateDeleteResult( $deleteResult, $embeddedDeletes );

		if( $combinedResult->getEmbeddedDeletedCount() + $combinedResult->getDeletedCount() + $combinedResult->getModifiedCount() + $combinedResult->getEmbeddedModifiedCount()==0 ) {
			throw new modelDocumentNotFoundException( static::_getHumanName( capitalize: true ) . ' not deleted because it was not found' );
		}

		//AUDIT CHANGE STREAM
		/*if( $mdb->audit && static::_getCollectionName()!='audit' ) {
			$auditEntries = $auditManager->processChangeStream( $combinedResult );
			if( count( $auditEntries )>0 ) {
				try {
					audit::saveMany( $auditEntries );
				}
				catch( modelException $e ) {
					log::error( 'MongoService', 'Failed to save audit log entries' . $e->getMessage() );
				}
			}
		}*/

		return $combinedResult;
	}


	/**
	 * @param static[]                     $itemsToDelete
	 * @param \MongoDB\Driver\Session|null $mongoDbSession
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function deleteMany( array $itemsToDelete, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult {
		if(count($itemsToDelete)===0) {
			return new updateDeleteResult();
		}
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		//AUDIT CHANGE STREAM
		/*$auditManager = new auditManager($mdb);
		if( $mdb->audit && static::_getCollectionName()!='audit' ) {
			$auditManager->startChangeStreamWatchOnDb();
		}*/

		log::info( 'MongoService', 'Delete many ' . static::_getCollectionName() );

		$_ids = [];
		foreach( $itemsToDelete as $itemToDelete ) {
			$_ids[] = $itemToDelete->_id;
		}

		//open database session if one is not already open
		$sessionParent = false;
		if( !isset( $mongoDbSession ) ) {
			$sessionParent  = true;
			$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
			$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 5000 ] );
		}

		$filter = [
			'_id' => [
				'$in'=>$_ids
			]
		];

		$options = [
			'session' => $mongoDbSession
		];

		try {
			$deleteResult = $mdb->collection->deleteMany( $filter, $options );

			$deleteCascadeResults = self::_deleteCascade( $itemsToDelete, $mongoDbSession );

			//dispatch delete for all embedded versions
			$embeddedDeletes = self::_deleteEmbedded( get_called_class(), $_ids, $mongoDbSession );

			//commit session
			if( $sessionParent ) {
				$mongoDbSession->commitTransaction();
			}
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}
			log::info( 'MongoService', 'delete many runtime exception ' . static::_getCollectionName() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}
		catch( modelException $e ) {
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}
			log::info( 'MongoService', 'delete cascade failure ' . static::_getCollectionName() );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//combine primary delete with embedded
		$combinedResult = new updateDeleteResult( $deleteResult, array_merge($embeddedDeletes, $deleteCascadeResults) );

		//AUDIT CHANGE STREAM
		/*if( $mdb->audit && static::_getCollectionName()!='audit') {
			$auditEntries = $auditManager->processChangeStream( $combinedResult );
			if( count( $auditEntries )>0 ) {
				try {
					audit::saveMany( $auditEntries );
				}
				catch( modelException $e ) {
					log::error( 'MongoService', 'Failed to save audit log entries' . $e->getMessage() );
				}
			}
		}*/

		return $combinedResult;
	}


	/**
	 * @param \gcgov\framework\services\mongodb\model $object
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private static function autoIncrementProperties( model $object, ?\MongoDB\Driver\Session $mongoDbSession = null ): updateDeleteResult {
		$className = $object::class;

		log::info( 'MongoServiceAutoIncrement', 'Start auto increment ' . $className );

		//find fields marked with autoIncrement attribute on $object
		try {
			$reflectionCacheClass = reflectionCache::getReflectionClass( $className );
			/** @var attributes\autoIncrement[] $autoIncrementAttributes */
			$autoIncrementAttributes = $reflectionCacheClass->getAttributeInstancesByPropertyName( autoIncrement::class );
		}
		catch( \ReflectionException $e ) {
			log::error( 'MongoServiceAutoIncrement', 'Auto increment reflection error ' . $className );
			error_log( $e );
			return new updateDeleteResult();
		}

		//if no properties are auto increments, do nothing
		if( count( $autoIncrementAttributes )===0 ) {
			return new updateDeleteResult();
		}

		//do the auto incrementing
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		//open database session if one is not already open
		$sessionParent = false;
		if( !isset( $mongoDbSession ) ) {
			$sessionParent  = true;
			$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
			$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 2000 ] );
		}

		try {
			$mongodbSet = [];

			foreach( $autoIncrementAttributes as $propertyName => $autoIncrement ) {
				//create key for auto increment group
				$key = static::_getCollectionName() . '.' . $propertyName;
				if( $autoIncrement->groupByPropertyName!=='' ) {
					$key .= '.' . $object->{$autoIncrement->groupByPropertyName};
				}
				if( $autoIncrement->groupByMethodName!=='' ) {
					$key .= '.' . $object->{$autoIncrement->groupByMethodName}();
				}
				log::info( 'MongoServiceAutoIncrement', '--' . $key );

				//get and increment internalCounter
				$internalCounter = \gcgov\framework\services\mongodb\models\internalCounter::getAndIncrement( $key, $mongoDbSession );

				//set the new count on the object (by ref)
				//if the value is supposed to be formatted
				if( $autoIncrement->countFormatMethod!=='' ) {
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
				'session' => $mongoDbSession
			];
			$updateResult = $mdb->collection->updateOne( $filter, $update, $options );

			//$session->commitTransaction();
		}
		catch( \MongoDB\Driver\Exception\RuntimeException|\MongoDB\Driver\Exception\CommandException $e ) {
			if( $sessionParent && $mongoDbSession->isInTransaction() ) {
				$mongoDbSession->abortTransaction();
			}
			$message = 'Error incrementing values for collection ' . static::_getCollectionName() . ' properties ' . implode( ', ', array_keys( $mongodbSet ) ) . ': ' . $e->getMessage();
			log::error( 'MongoServiceAutoIncrement', '--' . $message, $e->getTrace() );
			if( isset( $filter ) ) {
				log::error( 'MongoServiceAutoIncrement', '--Filter', $filter );
			}
			if( isset( $update ) ) {
				log::error( 'MongoServiceAutoIncrement', '--Update', $update );
			}
			throw new \gcgov\framework\exceptions\modelException( $message, 500, $e );
		}

		log::info( 'MongoServiceAutoIncrement', '--Matched: ' . $updateResult->getMatchedCount() );
		log::info( 'MongoServiceAutoIncrement', '--Modified: ' . $updateResult->getModifiedCount() );
		log::info( 'MongoServiceAutoIncrement', '--Upserted: ' . $updateResult->getUpsertedCount() );

		return new updateDeleteResult( $updateResult );
	}


	/**
	 * Run a Mongo db aggregation on the collection
	 * @return array
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function aggregation( array $pipeline = [], $options = [] ): array {
		$mdb = new tools\mdb( collection: static::_getCollectionName() );

		try {
			$cursor = $mdb->collection->aggregate( $pipeline, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		return $cursor->toArray();
	}

}
