<?php

namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class sqlDatabase
	implements
	jsonDeserialize {

	public bool            $default = false;

	public string          $name    = '';

	public string          $dsn     = '';

	public sqlDatabaseUser $readAccount;

	public sqlDatabaseUser $writeAccount;


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\environment\sqlDatabase
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : sqlDatabase {
		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config/environment.sqlDatabases JSON', 500, $e );
			}
		}

		$sqlDatabase               = new sqlDatabase();
		$sqlDatabase->default      = $json->default ?? false;
		$sqlDatabase->name         = $json->name ?? '';
		$sqlDatabase->dsn          = $json->dsn ?? '';
		$sqlDatabase->readAccount  = isset( $json->readAccount ) ? sqlDatabaseUser::jsonDeserialize( $json->readAccount ) : new sqlDatabaseUser();
		$sqlDatabase->writeAccount = isset( $json->writeAccount ) ? sqlDatabaseUser::jsonDeserialize( $json->writeAccount ) : new sqlDatabaseUser();

		return $sqlDatabase;
	}

}