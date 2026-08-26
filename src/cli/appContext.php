<?php

namespace gcgov\framework\cli;

use gcgov\framework\models\config\variantEnvironment;
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


	public function getConfigDir(): string {
		return $this->rootDir . '/app/config';
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
	 * Load and resolve the ACTIVE configuration from the unified {root}/config.json —
	 * no \app boot, no ext-mongodb. {root}/.env is loaded first; the real process
	 * environment wins. The CLI-only `environments` section is stripped before
	 * resolution (see loadVariantEnvironment()).
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function loadConfig(): unifiedConfig {
		if( !file_exists( $this->getConfigPath() ) ) {
			throw new cliException( 'Missing config file: ' . $this->getConfigPath() . '. Commit a config.json at the application root that references environment variables with %env(...) and supply values via the process environment or a .env file.' . $this->legacyConfigHint() );
		}

		try {
			return \gcgov\framework\services\environment\configLoader::load( $this->rootDir );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}
	}


	/**
	 * Load and resolve ONE entry of config.json's `environments` section — a
	 * foreign-environment read (db:restore --from, db:run --env, gf env <name>).
	 * The entry's %env() references should use environment-prefixed variable names
	 * (e.g. PROD_MONGO_URI, defined in {root}/.env), so a missing value fails
	 * loudly instead of resolving to a local value.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function loadVariantEnvironment( string $name ): variantEnvironment {
		if( !file_exists( $this->getConfigPath() ) ) {
			throw new cliException( 'Missing config file: ' . $this->getConfigPath() . '.' . $this->legacyConfigHint( $name ) );
		}

		try {
			return \gcgov\framework\services\environment\configLoader::loadVariantEnvironment( $this->rootDir, $name );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new cliException( $e->getMessage() . $this->legacyConfigHint( $name ), 0, $e );
		}
	}


	/**
	 * Migration hint when pre-v7 config layouts are present: the v6 split
	 * app/config/app.json + environment{-variant}.json files, or a pre-release
	 * {root}/{variant}.env overlay file.
	 */
	private function legacyConfigHint( string $variant = '' ): string {
		$legacyFiles = [
			$this->getConfigDir() . '/environment.json'  => 'app/config/environment.json',
			$this->getConfigDir() . '/app.json'          => 'app/config/app.json',
		];
		if( $variant!=='' ) {
			$legacyFiles[ $this->getConfigDir() . '/environment-' . $variant . '.json' ] = 'app/config/environment-' . $variant . '.json';
			$legacyFiles[ $this->rootDir . '/' . $variant . '.env' ]                     = $variant . '.env';
		}
		foreach( $legacyFiles as $legacyFile => $label ) {
			if( file_exists( $legacyFile ) ) {
				return ' A legacy ' . $label . ' exists — this framework version reads a single {root}/config.json whose `environments` section (with environment-prefixed variables like PROD_MONGO_URI in .env) replaces per-environment files; see readme/gf.md "Migrating a v6 app to v7".';
			}
		}

		return '';
	}


	/** Human-readable description of where an environment's config comes from, for error/guard messages. */
	public function describeConfigSource( string $variant = '' ): string {
		if( $variant==='' ) {
			return $this->getConfigPath();
		}

		return $this->getConfigPath() . ' (environments.' . $variant . ')';
	}


	/**
	 * Environment names declared in config.json's `environments` section — committed
	 * literals, so discovery and tab completion work on a fresh clone without any
	 * resolution or .env loading.
	 *
	 * @return string[]
	 */
	public function getEnvironmentVariants(): array {
		return \gcgov\framework\services\environment\configLoader::variantNames( $this->rootDir );
	}

}
