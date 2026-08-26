<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

use gcgov\framework\models\config\variantEnvironment;
use gcgov\framework\models\unifiedConfig;

/**
 * The single implementation of the config-load pipeline, shared by the request
 * lifecycle (\gcgov\framework\config) and the gf CLI (appContext):
 *
 *   {root}/config.json  →  load .env/.env.local  →  resolve %env(...)  →  hydrate
 *
 * All failures are thrown as the neutral environmentException; each caller wraps
 * it in its layer's exception type (configException / cliException).
 *
 * The `environments` section of config.json is CLI-only (foreign-environment
 * connection info) and is stripped before the active configuration is resolved,
 * so its environment-prefixed `%env()` references (e.g. PROD_MONGO_URI) never
 * have to be set for the app to run.
 */
final class configLoader {

	public const string FILE_NAME = 'config.json';


	public static function configFilePath( string $rootDir ): string {
		return rtrim( str_replace( '\\', '/', $rootDir ), '/' ) . '/' . self::FILE_NAME;
	}


	/**
	 * Load and resolve the ACTIVE configuration (ambient environment; the
	 * `environments` section is stripped).
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

		unset( $decoded->environments );
		envVarResolver::resolveDecoded( $decoded, $configFile );

		return self::hydrate( unifiedConfig::class, $decoded, $configFile );
	}


	/**
	 * Load and resolve ONE entry of the `environments` section — a
	 * foreign-environment read for the gf CLI.
	 *
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function loadVariantEnvironment( string $rootDir, string $name ): variantEnvironment {
		$configFile = self::configFilePath( $rootDir );
		$decoded    = self::readAndDecode( $rootDir, $configFile );

		if( is_string( $decoded ) ) {
			throw new environmentException( 'Failed to parse ' . $configFile . ': the file is not a valid JSON object.' );
		}

		$environments = $decoded->environments ?? null;
		if( !$environments instanceof \stdClass || !isset( $environments->{$name} ) || !$environments->{$name} instanceof \stdClass ) {
			$available = $environments instanceof \stdClass ? array_keys( get_object_vars( $environments ) ) : [];
			throw new environmentException( 'No "' . $name . '" entry in the environments section of ' . $configFile . '. ' . ( count( $available )>0 ? 'Defined environments: ' . implode( ', ', $available ) . '.' : 'Define one, e.g. "environments": { "' . $name . '": { "type": "' . $name . '", "mongoDatabases": [ { "default": true, "database": "%env(' . strtoupper( $name ) . '_MONGO_DATABASE)%", "uri": "%env(' . strtoupper( $name ) . '_MONGO_URI)%" } ] } } with the variable values in your .env.' ) );
		}

		$source = $configFile . ' (environments.' . $name . ')';
		envVarResolver::resolveDecoded( $environments->{$name}, $source );

		return self::hydrate( variantEnvironment::class, $environments->{$name}, $source );
	}


	/**
	 * Environment names declared in config.json's `environments` section.
	 * Read WITHOUT resolution or .env loading — the keys are literals — so this
	 * is safe for tab completion in any state.
	 *
	 * @return string[]
	 */
	public static function variantNames( string $rootDir ): array {
		$configFile = self::configFilePath( $rootDir );
		if( !file_exists( $configFile ) ) {
			return [];
		}

		$decoded = json_decode( (string)file_get_contents( $configFile ), false );
		if( !$decoded instanceof \stdClass || !( $decoded->environments ?? null ) instanceof \stdClass ) {
			return [];
		}

		$names = array_keys( get_object_vars( $decoded->environments ) );
		sort( $names );

		return $names;
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
