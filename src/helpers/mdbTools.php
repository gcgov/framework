<?php


namespace gcgov\framework\helpers;


final class mdbTools {

	/**
	 * @param  string|\stdClass  $json
	 * @param  string            $modelExceptionMessage
	 * @param  int               $modelExceptionCode
	 *
	 * @return \stdClass
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function jsonToObject( string|\stdClass $json, $modelExceptionMessage = 'Malformed JSON', $modelExceptionCode = 400 ) : \stdClass {

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


	/**
	 * @param  string  $_id
	 * @param  string  $modelExceptionMessage
	 * @param  int     $modelExceptionCode
	 *
	 * @return \MongoDB\BSON\ObjectId
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function stringToObjectId( string $_id, string $modelExceptionMessage = 'Invalid _id', int $modelExceptionCode = 400 ) : \MongoDB\BSON\ObjectId {

		try {
			return !empty( $_id ) ? new \MongoDB\BSON\ObjectId( $_id ) : new \MongoDB\BSON\ObjectId();
		}
		catch( \MongoDB\Driver\Exception\InvalidArgumentException $e ) {
			throw new \gcgov\framework\exceptions\modelException( $modelExceptionMessage, $modelExceptionCode );
		}

	}


	/**
	 * @param  string  $_id
	 * @param  string  $modelExceptionMessage
	 * @param  int     $modelExceptionCode
	 *
	 * @return ?\MongoDB\BSON\ObjectId
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function stringToObjectIdOrNull( string $_id, string $modelExceptionMessage = 'Invalid _id', int $modelExceptionCode = 400 ) : ?\MongoDB\BSON\ObjectId {

		try {
			return !empty( $_id ) ? new \MongoDB\BSON\ObjectId( $_id ) : null;
		}
		catch( \MongoDB\Driver\Exception\InvalidArgumentException $e ) {
			throw new \gcgov\framework\exceptions\modelException( $modelExceptionMessage, $modelExceptionCode );
		}

	}


	/**
	 * @param  \MongoDB\BSON\UTCDateTime|null  $utcDateTime
	 *
	 * @return \DateTimeImmutable|null
	 */
	public static function bsonUTCDatetimeToDateTimeImmutable( ?\MongoDB\BSON\UTCDateTime $utcDateTime = null ) : ?\DateTimeImmutable {

		if( $utcDateTime instanceof \MongoDB\BSON\UTCDateTime ) {
			$dt = $utcDateTime->toDateTime();

			return \DateTimeImmutable::createFromMutable( $dt )->setTimezone( new \DateTimeZone( "America/New_York" ) );
		}

		return new \DateTimeImmutable();
	}

	/**
	 * @param  \MongoDB\BSON\UTCDateTime|null  $utcDateTime
	 *
	 * @return \DateTimeImmutable|null
	 */
	public static function bsonUTCDatetimeToDateTimeImmutableOrNull( ?\MongoDB\BSON\UTCDateTime $utcDateTime = null ) : ?\DateTimeImmutable {

		if( $utcDateTime instanceof \MongoDB\BSON\UTCDateTime ) {
			$dt = $utcDateTime->toDateTime();

			return \DateTimeImmutable::createFromMutable( $dt )->setTimezone( new \DateTimeZone( "America/New_York" ) );
		}

		return null;
	}

}