<?php

namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class microsoft
	implements
	jsonDeserialize {

	public string $clientId     = "";

	public string $clientSecret = "";

	public string $tenant       = "";

	public string $driveId      = "";

	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\environment\microsoft
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : microsoft {
		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config/environment/microsft JSON', 500, $e );
			}
		}

		$microsoft               = new microsoft();
		$microsoft->clientId     = $json->clientId ?? '';
		$microsoft->clientSecret     = $json->clientSecret ?? '';
		$microsoft->tenant     = $json->tenant ?? '';
		$microsoft->driveId     = $json->driveId ?? '';

		return $microsoft;
	}

}