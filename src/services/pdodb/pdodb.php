<?php

namespace gcgov\framework\services\pdodb;


use gcgov\framework\config;
use PDO;


class pdodb extends PDO {

	/**
	 * @param  bool    $readOnly
	 * @param  string  $databaseName
	 *
	 * @throws \PDOException
	 */
	public function __construct( bool $readOnly=true, string $databaseName='' ) {
		$envConfig = config::getEnvironmentConfig();

		if(count($envConfig->sqlDatabases)===0) {
			throw new \PDOException('No database connectors are defined in the app environment config');
		}

		//find the matching database
		/** @var ?\gcgov\framework\models\config\environment\sqlDatabase $useSqlDatabase */
		$useSqlDatabase = null;
		foreach($envConfig->sqlDatabases as $sqlDatabase) {
			if($databaseName==='' && $sqlDatabase->default) {
				$useSqlDatabase = $sqlDatabase;
				break;
			}
			elseif($databaseName!=='' && $databaseName===$sqlDatabase->name) {
				$useSqlDatabase = $sqlDatabase;
				break;
			}
		}

		if($useSqlDatabase===null && $databaseName==='') {
			throw new \PDOException('No default database connector defined in the app environment config');
		}
		elseif($useSqlDatabase===null && $databaseName!=='') {
			throw new \PDOException('No database connector with name '.$databaseName.' defined in the app environment config');
		}


		//create the connection
		if($readOnly) {
			parent::__construct( $useSqlDatabase->dsn, $useSqlDatabase->readAccount->username, $useSqlDatabase->readAccount->password );
		}
		else {
			parent::__construct( $useSqlDatabase->dsn, $useSqlDatabase->writeAccount->username, $useSqlDatabase->writeAccount->password );
		}
	}

}