<?php
namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\models\config\environment\mongoDatabase;


final class mdb {

	/** @var \MongoDB\Client */
	public \MongoDB\Client $client;

	/** @var \MongoDB\Database */
	public \MongoDB\Database $db;

	/** @var \MongoDB\Collection */
	public \MongoDB\Collection $collection;

	/** @var bool */
	public bool $audit = false;


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

		try {
			$this->client = new \MongoDB\Client( $connector->uri, $connector->clientParams );

			$this->db = $this->client->{$connector->database};

			$this->collection = $this->db->{$collection};

			$this->audit = $connector->audit;
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
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

}