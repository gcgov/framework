<?php

namespace gcgov\framework\models;


use gcgov\framework\exceptions\configException;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\mongoDatabase;
use JetBrains\PhpStorm\Deprecated;


class environmentConfig {

	public string $type       = '';

	public string $serverName = '';

	public string $rootUrl    = '';

	public string $basePath   = '';

	#[Deprecated]
	/** @deprecated */
	public string $baseUrl    = '';

	public string $cookieUrl  = '';

	public string $phpPath    = '';

	/** @var \gcgov\framework\models\config\environment\mongoDatabase[] */
	public array $mongoDatabases = [];

	/** @var \gcgov\framework\models\config\environment\sqlDatabase[] */
	public array $sqlDatabases = [];

	public microsoft $microsoft;

	public array $appDictionary = [];


	public function __construct() {
		$this->microsoft = new microsoft();
	}


	/**
	 * @param  string|\stdClass  $json
	 *
	 * @return \gcgov\framework\models\environmentConfig
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function jsonDeserialize( string|\stdClass $json ) : environmentConfig {
		if( is_string( $json ) ) {
			try {
				$json = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			}
			catch( \JsonException $e ) {
				throw new configException( 'Malformed environmentConfig JSON', 500, $e );
			}
		}

		$environmentConfig             = new environmentConfig();
		$environmentConfig->type       = $json->type ?? '';
		$environmentConfig->serverName = $json->serverName ?? '';
		$environmentConfig->rootUrl    = $json->rootUrl ?? '';
		$environmentConfig->basePath   = $json->basePath ?? '';
		$environmentConfig->baseUrl    = $json->baseUrl ?? '';
		$environmentConfig->cookieUrl  = $json->cookieUrl ?? '';
		$environmentConfig->phpPath    = $json->phpPath ?? '';

		if( isset( $json->mongoDatabases ) ) {
			foreach( $json->mongoDatabases as $mongoDatabase ) {
				$environmentConfig->mongoDatabases[] = mongoDatabase::jsonDeserialize( $mongoDatabase );
			}
		}

		if( isset( $json->sqlDatabases ) ) {
			foreach( $json->sqlDatabases as $sqlDatabase ) {
				$environmentConfig->sqlDatabases[] = mongoDatabase::jsonDeserialize( $sqlDatabase );
			}
		}

		if( isset( $json->microsoft ) ) {
			$environmentConfig->microsoft = microsoft::jsonDeserialize( $json->microsoft );
		}

		$environmentConfig->appDictionary    = isset($json->appDictionary) ? (array) $json->appDictionary : [];

		return $environmentConfig;
	}

	public function getRootUrl() : string {
		return rtrim($this->rootUrl, '/ ');
	}

	public function getBaseUrl() : string {
		return rtrim($this->rootUrl, '/ ').'/'.trim($this->basePath, '/ ');
	}

	public function getBasePath() : string {
		return '/'.trim($this->basePath, '/ ');
	}

}