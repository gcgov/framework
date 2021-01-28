<?php


namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class app implements jsonDeserialize {

	public string $title    = '';

	public string $guid   = '';

	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\app\app
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : app {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config.app JSON', 500, $e );
			}
		}

		$app             = new app();
		$app->title    = isset( $json->title ) ? $json->title : '';
		$app->guid     = isset( $json->guid ) ? $json->guid : '';

		return $app;
	}

}