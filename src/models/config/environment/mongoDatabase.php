<?php


namespace gcgov\framework\models\config\environment;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class mongoDatabase implements jsonDeserialize {

	public bool   $default    = false;

	public string $server     = '';

	public string $database   = '';

	public string $username   = '';

	public string $password   = '';

	public string $authSource = '';


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\environment\mongoDatabase
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : mongoDatabase {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config/environment.mongoDatabases JSON', 500, $e );
			}
		}

		$mongoDatabase             = new mongoDatabase();
		$mongoDatabase->default    = isset( $json->default ) ? $json->default : false;
		$mongoDatabase->server     = isset( $json->server ) ? $json->server : '';
		$mongoDatabase->database   = isset( $json->database ) ? $json->database : '';
		$mongoDatabase->username   = isset( $json->username ) ? $json->username : '';
		$mongoDatabase->password   = isset( $json->password ) ? $json->password : '';
		$mongoDatabase->authSource = isset( $json->authSource ) ? $json->authSource : '';


		return $mongoDatabase;
	}

}