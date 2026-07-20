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
	 * Parse app/config/environment{-$variant}.json directly — no \app boot, no ext-mongodb.
	 * $variant '' loads the active environment.json.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function loadEnvironmentConfig( string $variant = '' ): environmentConfig {
		$file = $this->getEnvironmentConfigPath( $variant );
		if( !file_exists( $file ) ) {
			$hint = $variant==='' ? ' Run `gf env <environment>` to activate an environment first.' : '';
			throw new cliException( 'Missing environment config file: ' . $file . '.' . $hint );
		}

		try {
			return environmentConfig::jsonDeserialize( (string)file_get_contents( $file ) );
		}
		catch( \andrewsauder\jsonDeserialize\exceptions\jsonDeserializeException $e ) {
			throw new cliException( 'Failed to parse ' . $file . ': ' . $e->getMessage(), 0, $e );
		}
	}


	public function getEnvironmentConfigPath( string $variant = '' ): string {
		$suffix = $variant==='' ? '' : '-' . $variant;

		return $this->getConfigDir() . '/environment' . $suffix . '.json';
	}


	/**
	 * Environment variant names available in app/config (environment-{name}.json).
	 *
	 * @return string[]
	 */
	public function getEnvironmentVariants(): array {
		$variants = [];
		foreach( glob( $this->getConfigDir() . '/environment-*.json' ) ?: [] as $file ) {
			$variants[] = substr( basename( $file, '.json' ), strlen( 'environment-' ) );
		}
		sort( $variants );

		return $variants;
	}

}
