<?php
namespace gcgov\framework\services\mongodb\tools;


class helpers {

	/**
	 * @param  \MongoDB\BSON\ObjectId|string  $_id
	 * @param  string                         $modelExceptionMessage
	 *
	 * @return \MongoDB\BSON\ObjectId
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function stringToObjectId( \MongoDB\BSON\ObjectId|string $_id, string $modelExceptionMessage = 'Invalid _id' ) : \MongoDB\BSON\ObjectId {
		if( is_string( $_id ) ) {
			try {
				$_id = new \MongoDB\BSON\ObjectId( $_id );
			}
			catch( \MongoDB\Driver\Exception\InvalidArgumentException $e ) {
				throw new \gcgov\framework\exceptions\modelException( $modelExceptionMessage, 400 );
			}
		}

		return $_id;
	}


	/**
	 * @param  string|\stdClass  $json
	 * @param  string            $modelExceptionMessage
	 * @param  int               $modelExceptionCode
	 *
	 * @return \stdClass|stdClass[]
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function jsonToObject( string|\stdClass $json, $modelExceptionMessage = 'Malformed JSON', $modelExceptionCode = 400 ) : \stdClass|array {
		if( $json === null ) {
			throw new \gcgov\framework\exceptions\modelException( $modelExceptionMessage, $modelExceptionCode, $e );
		}

		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new \gcgov\framework\exceptions\modelException( $modelExceptionMessage, $modelExceptionCode, $e );
			}
		}

		return $json;
	}

}