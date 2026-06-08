<?php

namespace gcgov\framework\models;


use gcgov\framework\models\config\environment\jwtAuth;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\payjunction;
use gcgov\framework\models\config\environment\sqlDatabase;
use JetBrains\PhpStorm\Deprecated;

class environmentConfig extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $type = '';

	public string $serverName = '';

	public string $rootUrl = '';

	public string $basePath = '';

	#[Deprecated]
	/** @deprecated */
	public string $baseUrl = '';

	public string $cookieUrl = '';

	public string $phpPath = '';

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
		$this->microsoft   = new microsoft();
		$this->jwtAuth     = new jwtAuth();
		$this->payjunction = new payjunction();
		$this->logging = new logging();
	}

	protected function _afterJsonDeserialize(): void {
		// jsonDeserialize may instantiate this class without invoking the
		// constructor, leaving typed-non-nullable properties uninitialized.
		// Use reflection so we can ask the engine about init state without
		// PHPStan narrowing the check away.
		if( !( new \ReflectionProperty( $this, 'microsoft' ) )->isInitialized( $this ) ) {
			$this->microsoft = new microsoft();
		}
		if( !( new \ReflectionProperty( $this, 'jwtAuth' ) )->isInitialized( $this ) ) {
			$this->jwtAuth = new jwtAuth();
		}
		if( !( new \ReflectionProperty( $this, 'payjunction' ) )->isInitialized( $this ) ) {
			$this->payjunction = new payjunction();
		}
		if( !( new \ReflectionProperty( $this, 'logging' ) )->isInitialized( $this ) ) {
			$this->logging = new logging();
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
