<?php


namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class sqlDatabaseUser implements jsonDeserialize  {

	public string $username = '';

	public string $password = '';


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\environment\sqlDatabaseUser
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : sqlDatabaseUser {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config/environment.sqlDatabases.readAccount or app/config/environment.sqlDatabases.writeAccount JSON', 500, $e );
			}
		}

		$sqlDatabaseUser            = new sqlDatabaseUser();
		$sqlDatabaseUser->username      = isset( $json->username ) ? $json->username : '';
		$sqlDatabaseUser->password      = isset( $json->password ) ? $json->password : '';

		return $sqlDatabaseUser;
	}

}