<?php

namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class payjunction
	implements
	jsonDeserialize {

	public string $username   = "";

	public string $password   = "";

	public string $apiKey     = "";

	public string $terminalId = "";

	public string $merchantId = "";


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\environment\payjunction
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : payjunction {
		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config/environment/payjunction JSON', 500, $e );
			}
		}

		$payjunction             = new payjunction();
		$payjunction->username   = $json->username ?? '';
		$payjunction->password   = $json->password ?? '';
		$payjunction->apiKey     = $json->apiKey ?? '';
		$payjunction->terminalId = $json->terminalId ?? '';
		$payjunction->merchantId = $json->merchantId ?? '';

		return $payjunction;
	}

}