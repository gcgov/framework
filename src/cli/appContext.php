<?php

namespace gcgov\framework\cli;

use gcgov\framework\models\environmentConfig;

/**
 * Locates the consuming application's root directory and provides lazy access
 * to the app's configuration without booting the full request lifecycle.
 *
 * gf command tiers:
 *  - no context needed:  list, help, completion — work anywhere
 *  - root only:          env, db:*, cert:*, deploy — need locate() + config JSON
 *  - app boot:           cli, cli:list — need assertAppLoadable() + getServiceNamespaces()
 */
final class appContext {

	/** Set by cli\application from bin/gf — the autoloader that booted this process. */
	public static ?string $composerAutoloadPath = null;


	private function __construct( public readonly string $rootDir ) {
	}


	/**
	 * Locate the application root: the directory containing vendor/autoload.php AND app/app.php.
	 * Priority:
	 *  1. The directory owning the composer autoloader that booted gf (composer's bin proxy
	 *     sets it) — makes gf independent of the current working directory.
	 *  2. Walk up from $startDir (default: cwd).
	 * Returns null when gf is not running inside an application (e.g. inside the framework repo).
	 */
	public static function locate( ?string $startDir = null ): ?appContext {
		foreach( self::candidateRoots( $startDir ) as $dir ) {
			if( file_exists( $dir . '/vendor/autoload.php' ) && file_exists( $dir . '/app/app.php' ) ) {
				return new appContext( $dir );
			}
		}

		return null;
	}


	/**
	 * Like locate() but for `gf setup`: a freshly scaffolded project has app/ and composer.json
	 * but its config files still contain {placeholder} tokens, so require less.
	 */
	public static function locateScaffold( ?string $startDir = null ): ?appContext {
		foreach( self::candidateRoots( $startDir ) as $dir ) {
			if( file_exists( $dir . '/composer.json' ) && is_dir( $dir . '/app' ) ) {
				return new appContext( $dir );
			}
		}

		return null;
	}


	/**
	 * Locate the application root or fail with a user-facing error.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function require( ?string $startDir = null ): appContext {
		$context = self::locate( $startDir );
		if( $context===null ) {
			throw new cliException( 'This command must be run from inside a gcgov/framework application (a directory containing vendor/autoload.php and app/app.php). Current directory: ' . ( $startDir ?? (string)getcwd() ) );
		}

		return $context;
	}


	/**
	 * @return string[] Root directory candidates, most authoritative first
	 */
	private static function candidateRoots( ?string $startDir ): array {
		$candidates = [];

		if( self::$composerAutoloadPath!==null ) {
			// {root}/vendor/autoload.php -> {root}
			$candidates[] = self::normalize( dirname( self::$composerAutoloadPath, 2 ) );
		}

		$dir = $startDir ?? getcwd();
		if( $dir!==false ) {
			$dir = self::normalize( $dir );
			while( true ) {
				$candidates[] = $dir;
				$parent = dirname( $dir );
				if( $parent===$dir ) {
					break;
				}
				$dir = $parent;
			}
		}

		return array_unique( $candidates );
	}


	private static function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}


	public function getAppDir(): string {
		return $this->rootDir . '/app';
	}


	public function getConfigDir(): string {
		return $this->rootDir . '/app/config';
	}


	public function getSrvDir(): string {
		return $this->rootDir . '/srv';
	}


	public function getVendorAutoloadPath(): string {
		return $this->rootDir . '/vendor/autoload.php';
	}


	/**
	 * Verify \app\app is autoloadable from this process (required before booting app code).
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function assertAppLoadable(): void {
		if( !class_exists( '\app\app' ) ) {
			throw new cliException( '\app\app is not autoloadable. Run gf from the application root via vendor/bin/gf so the application autoloader is used. Application root detected: ' . $this->rootDir );
		}
	}


	/**
	 * Service namespaces registered by the app. Instantiates \app\app but deliberately
	 * does NOT run \app\app::_before() — no lifecycle side effects for enumeration.
	 *
	 * @return string[]
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function getServiceNamespaces(): array {
		$this->assertAppLoadable();
		$app = new \app\app();

		return $app->registerFrameworkServiceNamespaces();
	}


	/**
	 * Parse app/config/environment.json directly — no \app boot, no ext-mongodb.
	 *
	 * $variant ''     → resolve against the ambient environment ({root}/.env is loaded
	 *                   first; the real process environment wins).
	 * $variant 'name' → resolve the SAME environment.json with the variables from
	 *                   app/config/{name}.env applied as an overlay that takes precedence
	 *                   over the ambient environment — a foreign-environment read (used by
	 *                   db:restore/db:run/env) without activating anything. Variables
	 *                   missing from the overlay fall back to ambient values, so overlay
	 *                   files should define every environment-specific variable.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function loadEnvironmentConfig( string $variant = '' ): environmentConfig {
		$file       = $this->getEnvironmentConfigPath();
		$legacyFile = $this->getConfigDir() . '/environment-' . $variant . '.json';
		$legacyHint = $variant!=='' && file_exists( $legacyFile )
			? ' A legacy ' . basename( $legacyFile ) . ' exists — this framework version reads variant values from app/config/{name}.env overlay files instead; see readme/gf.md "Migrating a v6 app to v7".'
			: '';

		if( !file_exists( $file ) ) {
			throw new cliException( 'Missing environment config file: ' . $file . '. Commit an environment.json that references environment variables with %env(...) and supply values via the process environment or a .env file.' . $legacyHint );
		}

		$overlayVars = [];
		$source      = $file;
		if( $variant!=='' ) {
			$overlayPath = $this->getEnvironmentOverlayPath( $variant );
			if( !file_exists( $overlayPath ) ) {
				throw new cliException( 'Missing environment overlay file: ' . $overlayPath . '. Create it with the "' . $variant . '" environment\'s variable values (see app/config/prod.env.example in the app template).' . $legacyHint );
			}
			try {
				$overlayVars = \gcgov\framework\services\environment\dotEnvLoader::parseFile( $overlayPath );
			}
			catch( \gcgov\framework\services\environment\environmentException $e ) {
				throw new cliException( $e->getMessage(), 0, $e );
			}
			$source = $this->describeEnvironmentConfigSource( $variant );
		}

		\gcgov\framework\services\environment\dotEnvLoader::loadOnce( $this->rootDir );

		try {
			$json = \gcgov\framework\services\environment\envVarResolver::resolveJson( (string)file_get_contents( $file ), $source, $overlayVars );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new cliException( 'Failed to resolve environment variables in ' . $source . ': ' . $e->getMessage(), 0, $e );
		}

		try {
			return environmentConfig::jsonDeserialize( $json );
		}
		catch( \andrewsauder\jsonDeserialize\exceptions\jsonDeserializeException $e ) {
			throw new cliException( 'Failed to parse ' . $file . ': ' . $e->getMessage(), 0, $e );
		}
	}


	public function getEnvironmentConfigPath(): string {
		return $this->getConfigDir() . '/environment.json';
	}


	/** The per-variant overlay env file read by loadEnvironmentConfig($variant). */
	public function getEnvironmentOverlayPath( string $variant ): string {
		return $this->getConfigDir() . '/' . $variant . '.env';
	}


	/** Human-readable description of where a variant's config comes from, for error/guard messages. */
	public function describeEnvironmentConfigSource( string $variant = '' ): string {
		if( $variant==='' ) {
			return $this->getEnvironmentConfigPath();
		}

		return $this->getEnvironmentConfigPath() . ' (overlay: ' . $this->getEnvironmentOverlayPath( $variant ) . ')';
	}


	/**
	 * Environment variant names available in app/config ({name}.env overlay files).
	 * glob's `*` does not match a leading dot, and `*.env` does not match `*.env.example`,
	 * so a stray `.env` or the committed example file never appear as variants.
	 *
	 * @return string[]
	 */
	public function getEnvironmentVariants(): array {
		$variants = [];
		foreach( glob( $this->getConfigDir() . '/*.env' ) ?: [] as $file ) {
			$variants[] = basename( $file, '.env' );
		}
		sort( $variants );

		return $variants;
	}

}
