<?php
namespace gcgov\framework\helpers;

use gcgov\framework\config;
use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\exceptions\modelException;


class mdb
{

    /** @var \MongoDB\Client */
    public \MongoDB\Client $client;

    /** @var \MongoDB\Database */
    public \MongoDB\Database $db;


	/**
	 * mdb constructor.
	 *
	 * @param  string  $database Optional - if you want to use a database other than the one marked as default:true in app/config/environment.json
	 *
	 * @throws \gcgov\framework\exceptions\modelException
	 */
    public function __construct( string $database='' )
    {

		$connector = $this->getConnector( $database );

        $mongoParams = [];

        if(!empty($connector->username)) {
        	$mongoParams['username'] = $connector->username;
        }
        if(!empty($connector->password)) {
        	$mongoParams['password'] = $connector->password;
        }
        if(!empty($connector->authSource)) {
        	$mongoParams['authSource'] = $connector->authSource;
        }

        try {
	        $this->client = new \MongoDB\Client('mongodb://' . $connector->server, $mongoParams);

	        $this->db = $this->client->{$connector->database};
        }
        catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
        	error_log($e);
			throw new modelException('Database connection issue: '.$e->getMessage(), 503, $e);
        }


    }


	/**
	 * @param  string  $database
	 *
	 * @return \gcgov\framework\models\config\environment\mongoDatabase
	 * @throws \gcgov\framework\exceptions\modelException
	 */
    private function getConnector( string $database='' ) : mongoDatabase {

	    $environmentConfig = config::getEnvironmentConfig();

	    foreach($environmentConfig->mongoDatabases as $mongoDatabase) {

		    if($mongoDatabase->default && $database==='') {
			    return $mongoDatabase;
	        }
		    elseif($database!=='' && $mongoDatabase->database===$database) {
			    return $mongoDatabase;
		    }
	    }

	    throw new modelException('No suitable Mongo Database connector found in environment config', 500);
    }
}