<?php
namespace gcgov\framework\helpers;


use gcgov\framework\config;
use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\exceptions\modelException;


final class mdb {

	/** @var \MongoDB\Client */
	public \MongoDB\Client $client;

	/** @var \MongoDB\Database */
	public \MongoDB\Database $db;

	/** @var \MongoDB\Collection */
	public \MongoDB\Collection $collection;


	/**
	 * mdb constructor.
	 *
	 * @param  string  $collection
	 * @param  string  $database  Optional - if you want to use a database other than the one marked as default:true in
	 *                            app/config/environment.json
	 *
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function __construct( string $collection, string $database = '' ) {

		$connector = $this->getConnector( $database );

		$mongoParams = [];

		if( !empty( $connector->username ) ) {
			$mongoParams[ 'username' ] = $connector->username;
		}
		if( !empty( $connector->password ) ) {
			$mongoParams[ 'password' ] = $connector->password;
		}
		if( !empty( $connector->authSource ) ) {
			$mongoParams[ 'authSource' ] = $connector->authSource;
		}

		try {
			$this->client = new \MongoDB\Client( 'mongodb://' . $connector->server, $mongoParams );

			$this->db = $this->client->{$connector->database};

			$this->collection = $this->db->{$collection};
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log( $e );
			throw new modelException( 'Database connection issue: ' . $e->getMessage(), 503, $e );
		}

	}


	/**
	 * @param  string  $database
	 *
	 * @return \gcgov\framework\models\config\environment\mongoDatabase
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private function getConnector( string $database = '' ) : mongoDatabase {

		$environmentConfig = config::getEnvironmentConfig();

		foreach( $environmentConfig->mongoDatabases as $mongoDatabase ) {

			if( $mongoDatabase->default && $database === '' ) {
				return $mongoDatabase;
			}
			elseif( $database !== '' && $mongoDatabase->database === $database ) {
				return $mongoDatabase;
			}
		}

		throw new modelException( 'No suitable Mongo Database connector found in environment config', 500 );
	}


	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 *
	 * @return \MongoDB\BSON\ObjectId
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function stringToObjectId( \MongoDB\BSON\ObjectId|string $_id ) : \MongoDB\BSON\ObjectId {

		if( is_string( $_id ) ) {
			try {
				$_id = new \MongoDB\BSON\ObjectId( $_id );
			}
			catch( \MongoDB\Driver\Exception\InvalidArgumentException $e ) {
				throw new \gcgov\framework\exceptions\modelException( 'Invalid _id', 400 );
			}
		}

		return $_id;

	}


	/**
	 * @param  array  $filter   Optional
	 * @param  array  $sort     Optional
	 * @param  array  $typeMap  Optional for typecasting
	 *
	 * @return array
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function getAll( array $filter = [], array $sort = [], array $typeMap = [] ) : array {

		$options = [];
		if(count($sort)>0) {
			$options['sort'] = $sort;
		}
		if(count($typeMap)>0) {
			$options['typeMap'] = $typeMap;
		}

		try {
			$cursor = $this->collection->find( $filter, $options );

			return $cursor->toArray();
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log( $e );
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

	}


	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 * @param  array                          $typeMap
	 * @param  string                         $notFoundMessage
	 *
	 * @return mixed
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function getOne( \MongoDB\BSON\ObjectId|string $_id, array $typeMap = [], string $notFoundMessage='' ) : mixed {

		$_id = $this->stringToObjectId( $_id );

		$filter = [
			'_id' => $_id
		];

		$options = [
			'typeMap' => $typeMap,
		];

		$cursor = $this->collection->findOne( $filter, $options );

		if( $cursor === null ) {
			if($notFoundMessage==='') {
				$notFoundMessage = $this->collection.' not found';
			}
			throw new \gcgov\framework\exceptions\modelException( $notFoundMessage, 404 );
		}

		return $cursor;

	}


	/**
	 * @param  object  $object
	 * @param  bool    $upsert  Optional
	 *
	 * @return \MongoDB\UpdateResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function save( object $object, bool $upsert=true ) : \MongoDB\UpdateResult {

		if( $object->_id == 'new' || empty( $object->_id ) ) {
			$object->_id = new \MongoDB\BSON\ObjectId();
		}

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
			return $this->collection->updateOne( $filter, $update, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log($e);
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e);
		}

	}


	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 *
	 * @return \MongoDB\DeleteResult
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function delete( \MongoDB\BSON\ObjectId|string $_id ) : \MongoDB\DeleteResult {

		$_id = $this->stringToObjectId( $_id );

		$filter = [
			'_id' => $_id
		];

		$options = [
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		try {
			return $this->collection->deleteOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			error_log($e);
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e);
		}

	}

}