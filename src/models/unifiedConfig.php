<?php

namespace gcgov\framework\models;


use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;
use gcgov\framework\models\config\environment\cronMonitor;
use gcgov\framework\models\config\environment\jwtAuth;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\payjunction;
use gcgov\framework\models\config\environment\sqlDatabase;
use gcgov\framework\models\config\services;
use JetBrains\PhpStorm\Deprecated;

/**
 * The application's unified configuration, hydrated from the single {root}/config.json
 * (the v7 merge of the former app/config/app.json and app/config/environment.json).
 *
 * Application code normally reads configuration through the static accessors on
 * \gcgov\framework\config; this model is the hydration target (via
 * services\environment\configLoader) and what appContext::loadConfig() returns
 * in the gf CLI.
 */
class unifiedConfig extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	// --- application identity (formerly app.json) ---

	public app      $app;

	public email    $email;

	public settings $settings;

	// --- environment (formerly environment.json) ---

	public string $type = '';

	public string $rootUrl = '';

	public string $basePath = '';

	#[Deprecated]
	/** @deprecated */
	public string $baseUrl = '';

	/** @var \gcgov\framework\models\config\environment\mongoDatabase[] */
	public array $mongoDatabases = [];

	/** @var \gcgov\framework\models\config\environment\sqlDatabase[] */
	public array $sqlDatabases = [];

	public microsoft $microsoft;

	public jwtAuth $jwtAuth;

	public payjunction $payjunction;

	public logging $logging;

	public cronMonitor $cronMonitor;

	// --- framework services ---

	public services $services;

	public array $appDictionary = [];


	public function __construct() {
		$this->app         = new app();
		$this->email       = new email();
		$this->settings    = new settings();
		$this->microsoft   = new microsoft();
		$this->jwtAuth     = new jwtAuth();
		$this->payjunction = new payjunction();
		$this->logging     = new logging();
		$this->cronMonitor = new cronMonitor();
		$this->services    = new services();
	}

	protected function _afterJsonDeserialize(): void {
		// jsonDeserialize may instantiate this class without invoking the
		// constructor, leaving typed-non-nullable properties uninitialized.
		// Use reflection so we can ask the engine about init state without
		// PHPStan narrowing the check away.
		foreach( [ 'app' => app::class, 'email' => email::class, 'settings' => settings::class, 'microsoft' => microsoft::class, 'jwtAuth' => jwtAuth::class, 'payjunction' => payjunction::class, 'logging' => logging::class, 'cronMonitor' => cronMonitor::class, 'services' => services::class ] as $property => $class ) {
			if( !( new \ReflectionProperty( $this, $property ) )->isInitialized( $this ) ) {
				$this->$property = new $class();
			}
		}
	}

	public function getRootUrl(): string {
		return rtrim( $this->rootUrl, '/ ' );
	}


	public function getBaseUrl(): string {
		return rtrim( rtrim( $this->rootUrl, '/ ' ) . '/' . trim( $this->basePath, '/ ' ), '/' );
	}


	public function getBasePath(): string {
		return '/' . trim( $this->basePath, '/ ' );
	}


	/**
	 * The base path in the form a route pattern is built from: '' at the domain root,
	 * '/api' otherwise.
	 *
	 * {@see getBasePath()} cannot serve this purpose. It returns '/' at the domain root
	 * — correct for the token audience, which is its other use — and concatenating that
	 * with a leading-slash route yields '//user', which FastRoute registers and matches
	 * as that literal string. Every router prefixing a route uses this instead.
	 */
	public function getRoutePrefix(): string {
		return rtrim( $this->getBasePath(), '/' );
	}


	/**
	 * Where the JWT signing keypairs live: jwtAuth.keyPath when set, else
	 * {srvDir}/jwtCertificates. Always returned with a trailing slash.
	 *
	 * The srv directory is an argument because the two callers reach it differently — the
	 * request lifecycle through config::getSrvDir(), the gf CLI through appContext, which
	 * never boots \app. They previously resolved the location independently, so
	 * `gf cert:generate-auth` wrote keys to srv/jwtCertificates while jwtAuth looked in the
	 * configured keyPath and reported the very command that had just run as the remedy.
	 */
	public function getJwtKeyPath( string $srvDir ): string {
		$configured = trim( $this->jwtAuth->keyPath );
		if( $configured!=='' ) {
			return rtrim( str_replace( '\\', '/', $configured ), '/' ) . '/';
		}

		return rtrim( str_replace( '\\', '/', $srvDir ), '/' ) . '/jwtCertificates/';
	}


	/** Token issuer, defaulting to the application's own root url. */
	public function getTokenIssuedBy(): string {
		return $this->jwtAuth->tokenIssuedBy!=='' ? $this->jwtAuth->tokenIssuedBy : $this->getRootUrl();
	}


	/** Token audience, defaulting to the application's own base path. */
	public function getTokenPermittedFor(): string {
		return $this->jwtAuth->tokenPermittedFor!=='' ? $this->jwtAuth->tokenPermittedFor : $this->getBasePath();
	}


	public function isLocal(): bool {
		return $this->type=='local';
	}


	public function getDefaultSqlDatabase(): ?sqlDatabase {
		foreach( $this->sqlDatabases as $sqlDatabase ) {
			if( $sqlDatabase->default ) {
				return $sqlDatabase;
			}
		}
		return null;
	}


	public function getSqlDatabaseByName( string $name ): ?sqlDatabase {
		foreach( $this->sqlDatabases as $sqlDatabase ) {
			if( $sqlDatabase->name===$name ) {
				return $sqlDatabase;
			}
		}
		return null;
	}

}
