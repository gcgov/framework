<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\exceptions\modelException;


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
	 * @return mixed
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOne( \MongoDB\BSON\ObjectId|string $_id ) : mixed {
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
	public static function save( $object, bool $upsert = true ) : updateDeleteResult {
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
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log($e);
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//dispatch updates for all embedded versions
		$embeddedUpdates = self::_updateEmbedded( $object );

		$combinedResult = new updateDeleteResult( $updateResult, $embeddedUpdates );

		//update _meta property of object to show results
		if(property_exists($object, '_meta')) {
			$object->_meta->db->set( $combinedResult );
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
			error_log($e);
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		//dispatch delete for all embedded versions
		$embeddedDeletes = self::_deleteEmbedded( static::_typeMap()->root, $_id );

		//combine primary delete with embedded
		$combinedResult = new updateDeleteResult( $deleteResult, $embeddedDeletes );

		if($combinedResult->getEmbeddedDeletedCount()+$combinedResult->getDeletedCount()+$combinedResult->getModifiedCount()+$combinedResult->getEmbeddedModifiedCount()==0) {
			throw new modelException( static::_getHumanName( capitalize: true ) . ' not deleted because it was not found', 404 );
		}

		return $combinedResult;
	}

}