<?php

namespace gcgov\framework\models;


use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;
use gcgov\framework\models\config\environment\jwtAuth;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\payjunction;
use gcgov\framework\models\config\environment\sqlDatabase;
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

	public array $appDictionary = [];


	public function __construct() {
		$this->app         = new app();
		$this->email       = new email();
		$this->settings    = new settings();
		$this->microsoft   = new microsoft();
		$this->jwtAuth     = new jwtAuth();
		$this->payjunction = new payjunction();
		$this->logging     = new logging();
	}

	protected function _afterJsonDeserialize(): void {
		// jsonDeserialize may instantiate this class without invoking the
		// constructor, leaving typed-non-nullable properties uninitialized.
		// Use reflection so we can ask the engine about init state without
		// PHPStan narrowing the check away.
		foreach( [ 'app' => app::class, 'email' => email::class, 'settings' => settings::class, 'microsoft' => microsoft::class, 'jwtAuth' => jwtAuth::class, 'payjunction' => payjunction::class, 'logging' => logging::class ] as $property => $class ) {
			if( !( new \ReflectionProperty( $this, $property ) )->isInitialized( $this ) ) {
				$this->$property = new $class();
			}
		}
	}

	public function getRootUrl(): string {
		return rtrim( $this->rootUrl, '/ ' );
	}


	public function getBaseUrl(): string {
		return rtrim( $this->rootUrl, '/ ' ) . '/' . trim( $this->basePath, '/ ' );
	}


	public function getBasePath(): string {
		return '/' . trim( $this->basePath, '/ ' );
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
