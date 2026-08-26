<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Idempotent wrapper over symfony/dotenv that loads a project's `.env` file(s)
 * once per process, before {root}/config.json is resolved by {@see envVarResolver}.
 *
 * Precedence (highest wins): real process environment > .env.local > .env.
 * The real container/process environment always wins — Symfony's Dotenv never
 * overrides variables that are already present in the environment. `usePutenv()`
 * is enabled so that call sites reading through `getenv()` (e.g. `GF_PHP` in the
 * gf CLI) also observe values loaded from .env files. Either file may exist on
 * its own — a project keeping only machine-local values in `.env.local` loads
 * exactly like one with only `.env`.
 *
 * There is deliberately no APP_ENV cascade: environment selection is simply
 * which variables the process environment (or .env) supplies. The gf CLI reads
 * a *foreign* environment's values via the `environments.{name}` section of
 * config.json, referencing distinctly-named variables (e.g. PROD_MONGO_URI)
 * that live in the same `.env`.
 */
final class dotEnvLoader {

	/** Root directories already processed, so loading is a no-op on repeat calls. */
	private static array $loadedRoots = [];


	/**
	 * Load {root}/.env and/or {root}/.env.local when present. No-op when neither
	 * exists or when this root has already been loaded in the current process.
	 *
	 * @throws \gcgov\framework\services\environment\environmentException When a present file has invalid syntax
	 */
	public static function loadOnce( string $rootDir ): void {
		$rootDir = rtrim( str_replace( '\\', '/', $rootDir ), '/' );

		if( isset( self::$loadedRoots[ $rootDir ] ) ) {
			return;
		}
		self::$loadedRoots[ $rootDir ] = true;

		// load() applies files left to right with later files overriding earlier ones,
		// never overriding real environment variables that are already set.
		$files = array_values( array_filter( [ $rootDir . '/.env', $rootDir . '/.env.local' ], 'file_exists' ) );
		if( count( $files )===0 ) {
			// Nothing to load; still marked as processed so we don't re-stat every call.
			return;
		}

		$dotenv = new Dotenv();
		$dotenv->usePutenv();

		try {
			$dotenv->load( ...$files );
		}
		catch( \Symfony\Component\Dotenv\Exception\FormatException $e ) {
			throw new environmentException( 'Invalid syntax in environment file (' . implode( ', ', $files ) . '): ' . $e->getMessage(), 0, $e );
		}
	}


	/**
	 * Reset the idempotency cache. Intended for test isolation only.
	 *
	 * @internal
	 */
	public static function resetForTesting(): void {
		self::$loadedRoots = [];
	}

}
