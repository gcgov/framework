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
		if(!isset($this->microsoft)) {
			$this->microsoft = new microsoft();
		}
		if(!isset($this->jwtAuth)) {
			$this->jwtAuth = new jwtAuth();
		}
		if(!isset($this->payjunction)) {
			$this->payjunction = new payjunction();
		}
		if(!isset($this->logging)) {
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
