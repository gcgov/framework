<?php


namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class email implements jsonDeserialize {

	public string $fromAddress = '';

	public string $fromName    = '';


	public function __construct() {
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\config\app\email
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : email {

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed app/config.app JSON', 500, $e );
			}
		}

		$email              = new email();
		$email->fromAddress = isset( $json->fromAddress ) ? $json->fromAddress : '';
		$email->fromName    = isset( $json->fromName ) ? $json->fromName : '';

		return $email;
	}

}