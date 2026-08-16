<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Idempotent wrapper over symfony/dotenv that loads a project's `.env` file(s)
 * once per process, before config JSON is resolved by {@see envVarResolver}.
 *
 * Precedence (highest wins): real process environment > .env.local > .env.
 * The real container/process environment always wins — Symfony's Dotenv never
 * overrides variables that are already present in the environment. `usePutenv()`
 * is enabled so that call sites reading through `getenv()` (e.g. `GF_PHP` in the
 * gf CLI) also observe values loaded from .env files.
 *
 * There is deliberately no APP_ENV cascade: environment selection stays with
 * gf's env-file copying (`gf env <name>`), not with dotenv.
 */
final class dotEnvLoader {

	/** Root directories already processed, so loading is a no-op on repeat calls. */
	private static array $loadedRoots = [];


	/**
	 * Load {root}/.env then {root}/.env.local when present. No-op when neither
	 * exists or when this root has already been loaded in the current process.
	 */
	public static function loadOnce( string $rootDir ): void {
		$rootDir = rtrim( str_replace( '\\', '/', $rootDir ), '/' );

		if( isset( self::$loadedRoots[ $rootDir ] ) ) {
			return;
		}
		self::$loadedRoots[ $rootDir ] = true;

		$envFile = $rootDir . '/.env';
		if( !file_exists( $envFile ) ) {
			// Nothing to load; still mark as processed so we don't re-stat every call.
			return;
		}

		$dotenv = new Dotenv();
		$dotenv->usePutenv();

		// load() reads .env and, when present, .env.local — never overriding real
		// environment variables that are already set.
		$files = [ $envFile ];
		$localFile = $rootDir . '/.env.local';
		if( file_exists( $localFile ) ) {
			$files[] = $localFile;
		}

		$dotenv->load( ...$files );
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
