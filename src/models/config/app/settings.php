<?php


namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class settings implements jsonDeserialize {

	public bool $useSession = false;


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\app\settings
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : settings {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config.app JSON', 500, $e );
			}
		}

		$settings              = new settings();
		$settings->useSession = isset( $json->useSession ) ? $json->useSession : false;

		return $settings;
	}

}