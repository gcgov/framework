<?php


namespace gcgov\framework\models;


use gcgov\framework\exceptions\configException;
use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;


class appConfig {

	public app      $app;

	public email    $email;

	public settings $settings;


	public function __construct() {

	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\appConfig
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : appConfig {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config JSON', 500, $e );
			}
		}

		$appConfig           = new appConfig();
		$appConfig->app      = isset( $json->app ) ? app::jsonDeserialize( $json->app ) : new app();
		$appConfig->email    = isset( $json->email ) ? email::jsonDeserialize( $json->email ) : new email();
		$appConfig->settings = isset( $json->settings ) ? settings::jsonDeserialize( $json->settings ) : new settings();

		return $appConfig;
	}

}