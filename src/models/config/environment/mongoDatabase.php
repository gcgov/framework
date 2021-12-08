<?php

namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class mongoDatabase
	implements
	jsonDeserialize {

	public bool   $default  = false;

	public string $uri      = '';

	public string $database = '';

	/** @var array Associative array to pass to the mongo client */
	public array $clientParams       = [];

	public bool  $include_meta       = false;

	public bool  $include_metaLabels = false;

	public bool  $include_metaFields = false;

	public bool  $audit              = false;


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

		$mongoDatabase                     = new mongoDatabase();
		$mongoDatabase->default            = $json->default ?? false;
		$mongoDatabase->uri                = $json->uri ?? '';
		$mongoDatabase->database           = $json->database ?? '';
		$mongoDatabase->clientParams       = isset( $json->clientParams ) ? (array) $json->clientParams : [];
		$mongoDatabase->audit              = $json->audit ?? false;
		$mongoDatabase->include_meta       = $json->include_meta ?? true;
		$mongoDatabase->include_metaLabels = $json->include_metaLabels ?? true;
		$mongoDatabase->include_metaFields = $json->include_metaFields ?? true;

		return $mongoDatabase;
	}

}