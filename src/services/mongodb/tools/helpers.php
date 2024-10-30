<?php
namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;

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


	public static function convertModelsToTypescript( string $typescriptFilePathName ): bool {
		try {
			$config = TypeScriptTransformerConfig::create()
				// path where your PHP classes are
				                                 ->autoDiscoverTypes( config::getRootDir() . '\vendor\gcgov\framework\src\\' )
			                                     ->autoDiscoverTypes( config::getAppDir() )
				// list of transformers
				                                 ->transformers( [ \Spatie\TypeScriptTransformer\Transformers\EnumTransformer::class, \Spatie\TypeScriptTransformer\Transformers\DtoTransformer::class ] )
				// file where TypeScript type definitions will be written
				                                 ->defaultTypeReplacements( [ \DateTimeImmutable::class => 'string', \MongoDB\BSON\ObjectId::class => 'string' ] )
			                                     ->writer( ModuleWriter::class )
			                                     ->outputFile( $typescriptFilePathName );

			TypeScriptTransformer::create( $config )->transform();
			return true;
		}
		catch(\Exception $e) {
			throw new \gcgov\framework\exceptions\modelException( 'Failed to convert', 400 );
		}
		return false;
	}
}
