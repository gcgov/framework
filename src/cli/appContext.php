<?php

namespace gcgov\framework\cli;

use gcgov\framework\models\unifiedConfig;

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


	/** The unified {root}/config.json read by loadConfig(). */
	public function getConfigPath(): string {
		return $this->rootDir . '/config.json';
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
	 * Load and resolve {root}/config.json — no \app boot, no ext-mongodb.
	 * {root}/.env is loaded first; the real process environment wins.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function loadConfig(): unifiedConfig {
		if( !file_exists( $this->getConfigPath() ) ) {
			throw new cliException( 'Missing config file: ' . $this->getConfigPath() . '. Commit a config.json at the application root that references environment variables with %env(...) and supply values via the process environment or a .env file. Migrating a v6 application? Run `gf migrate`.' );
		}

		try {
			return \gcgov\framework\services\environment\configLoader::load( $this->rootDir );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}
	}


	/**
	 * Every variable config.json references, and whether each is a secret. Read without
	 * resolving anything, so it works on a fresh clone with no .env.
	 *
	 * @return array<string, bool>
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function configReferences(): array {
		try {
			return \gcgov\framework\services\environment\configLoader::references( $this->rootDir );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}
	}


	public function getEnvFilePath(): string {
		return $this->rootDir . '/.env';
	}

}
