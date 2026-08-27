<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

use gcgov\framework\models\unifiedConfig;

/**
 * The single implementation of the config-load pipeline, shared by the request
 * lifecycle (\gcgov\framework\config) and the gf CLI (appContext):
 *
 *   {root}/config.json  →  load .env/.env.local  →  resolve %env(...)  →  hydrate
 *
 * All failures are thrown as the neutral environmentException; each caller wraps
 * it in its layer's exception type (configException / cliException).
 */
final class configLoader {

	public const string FILE_NAME = 'config.json';


	public static function configFilePath( string $rootDir ): string {
		return rtrim( str_replace( '\\', '/', $rootDir ), '/' ) . '/' . self::FILE_NAME;
	}


	/**
	 * Load and resolve the configuration.
	 *
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function load( string $rootDir ): unifiedConfig {
		$configFile = self::configFilePath( $rootDir );
		$decoded    = self::readAndDecode( $rootDir, $configFile );

		if( is_string( $decoded ) ) {
			// Malformed/non-object JSON: hand the raw string to jsonDeserialize so it
			// raises its normal, detailed parse error (wrapped below).
			return self::hydrate( unifiedConfig::class, $decoded, $configFile );
		}

		envVarResolver::resolveDecoded( $decoded, $configFile );

		return self::hydrate( unifiedConfig::class, $decoded, $configFile );
	}


	/**
	 * Every variable config.json references, WITHOUT resolving any of them — so it works
	 * on a machine where none are set yet. Backs `gf env --list` and `gf env --init`, which
	 * is what keeps the .env manifest from drifting away from config.json.
	 *
	 * @return array<string, bool>  variable name => is a secret
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function references( string $rootDir ): array {
		$configFile = self::configFilePath( $rootDir );
		if( !file_exists( $configFile ) ) {
			throw new environmentException( 'Missing config file: ' . $configFile );
		}

		$decoded = json_decode( (string)file_get_contents( $configFile ), false );
		if( !$decoded instanceof \stdClass ) {
			throw new environmentException( 'Failed to parse ' . $configFile . ': the file is not a valid JSON object.' );
		}

		return envVarResolver::collectReferences( $decoded, $configFile );
	}


	/**
	 * @return \stdClass|string  Decoded object, or the raw string when it does not decode to an object.
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function readAndDecode( string $rootDir, string $configFile ): \stdClass|string {
		if( !file_exists( $configFile ) ) {
			throw new environmentException( 'Missing config file: ' . $configFile );
		}

		dotEnvLoader::loadOnce( $rootDir );

		$json    = (string)file_get_contents( $configFile );
		$decoded = json_decode( $json, false );

		return $decoded instanceof \stdClass ? $decoded : $json;
	}


	/**
	 * @template T of \andrewsauder\jsonDeserialize\jsonDeserialize
	 *
	 * @param  class-string<T>   $class
	 * @param  \stdClass|string  $data
	 *
	 * @return T
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function hydrate( string $class, \stdClass|string $data, string $source ): object {
		try {
			return $class::jsonDeserialize( $data );
		}
		catch( \andrewsauder\jsonDeserialize\exceptions\jsonDeserializeException $e ) {
			throw new environmentException( 'Failed to parse ' . $source . ': ' . $e->getMessage(), 0, $e );
		}
	}

}
