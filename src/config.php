<?php

namespace gcgov\framework;


use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;
use gcgov\framework\models\config\environment\jwtAuth;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\payjunction;
use gcgov\framework\models\config\environment\sqlDatabase;
use gcgov\framework\models\unifiedConfig;


/**
 * Static configuration access for the application.
 *
 * Paths are derived by reflecting \app\app's file location. Configuration values come
 * from the single {root}/config.json (the v7 merge of the former app/config/app.json
 * and app/config/environment.json), resolved with %env(...) environment-variable
 * references, and are exposed directly on this class — e.g. config::getBasePath(),
 * config::getMongoDatabases(), config::getEmail().
 */
final class config {

	private static string $rootDir = '';

	private static string $appDir = '';

	private static string $modelsDir = '';

	private static string $servicesDir = '';

	private static string $srvDir = '';

	private static unifiedConfig $unifiedConfig;


	public static function getTempDir(): string {
		if( self::$rootDir==='' ) {
			self::setRootDir();
		}

		return self::$rootDir . '/srv/tmp/tmp';
	}


	public static function getRootDir(): string {
		if( self::$rootDir==='' ) {
			self::setRootDir();
		}

		return self::$rootDir;
	}


	private static function setRootDir(): void {
		$appDir        = self::getAppDir();
		self::$rootDir = substr( $appDir, 0, strrpos( $appDir, '/' ) );
	}


	public static function getAppDir(): string {
		if( self::$appDir==='' ) {
			self::setAppDir();
		}

		return self::$appDir;
	}


	private static function setAppDir(): void {
		$appClass     = new \ReflectionClass( '\app\app' );
		$appDir       = rtrim( dirname( $appClass->getFileName() ), '/\\' );
		$nixAppDir    = str_replace( '\\', '/', $appDir );
		self::$appDir = $nixAppDir;
	}


	public static function getModelsDir(): string {
		if( self::$modelsDir==='' ) {
			self::setModelsDir();
		}

		return self::$modelsDir;
	}


	private static function setModelsDir(): void {
		self::$modelsDir = self::getAppDir() . '/models/';
	}


	public static function getServicesDir(): string {
		if( self::$servicesDir==='' ) {
			self::setServicesDir();
		}

		return self::$servicesDir;
	}


	private static function setServicesDir(): void {
		self::$servicesDir = self::getAppDir() . '/services/';
	}


	public static function getSrvDir(): string {
		if( self::$srvDir==='' ) {
			self::setSrvDir();
		}

		return self::$srvDir;
	}


	private static function setSrvDir(): void {
		self::$srvDir = self::getRootDir() . '/srv/';
	}


	/**
	 * The absolute path of the unified config file.
	 */
	public static function getConfigFilePath(): string {
		return self::getRootDir() . '/config.json';
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	private static function unifiedConfig(): unifiedConfig {
		if( !isset( self::$unifiedConfig ) ) {
			self::setUnifiedConfig();
		}

		return self::$unifiedConfig;
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	private static function setUnifiedConfig(): void {
		$configFile = self::getConfigFilePath();
		if( !file_exists( $configFile ) ) {
			throw new \gcgov\framework\exceptions\configException( 'Missing config file at ' . $configFile );
		}

		\gcgov\framework\services\environment\dotEnvLoader::loadOnce( self::getRootDir() );
		try {
			$json = \gcgov\framework\services\environment\envVarResolver::resolveJson( (string)file_get_contents( $configFile ), $configFile );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new \gcgov\framework\exceptions\configException( $e->getMessage(), 500, $e );
		}

		self::$unifiedConfig = unifiedConfig::jsonDeserialize( $json );
	}


	// --- application identity (formerly app.json) ---

	/** @throws \gcgov\framework\exceptions\configException */
	public static function getApp(): app {
		return self::unifiedConfig()->app;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getEmail(): email {
		return self::unifiedConfig()->email;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getSettings(): settings {
		return self::unifiedConfig()->settings;
	}


	// --- environment (formerly environment.json) ---

	/** @throws \gcgov\framework\exceptions\configException */
	public static function getType(): string {
		return self::unifiedConfig()->type;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function isLocal(): bool {
		return self::unifiedConfig()->isLocal();
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getServerName(): string {
		return self::unifiedConfig()->serverName;
	}


	/** Normalized (no trailing slash). @throws \gcgov\framework\exceptions\configException */
	public static function getRootUrl(): string {
		return self::unifiedConfig()->getRootUrl();
	}


	/** {rootUrl}/{basePath}. @throws \gcgov\framework\exceptions\configException */
	public static function getBaseUrl(): string {
		return self::unifiedConfig()->getBaseUrl();
	}


	/** Normalized '/api' style ('/' at domain root). @throws \gcgov\framework\exceptions\configException */
	public static function getBasePath(): string {
		return self::unifiedConfig()->getBasePath();
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getCookieUrl(): string {
		return self::unifiedConfig()->cookieUrl;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getPhpPath(): string {
		return self::unifiedConfig()->phpPath;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getLogging(): logging {
		return self::unifiedConfig()->logging;
	}


	/**
	 * @return \gcgov\framework\models\config\environment\mongoDatabase[]
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getMongoDatabases(): array {
		return self::unifiedConfig()->mongoDatabases;
	}


	/**
	 * @return \gcgov\framework\models\config\environment\sqlDatabase[]
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getSqlDatabases(): array {
		return self::unifiedConfig()->sqlDatabases;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getDefaultSqlDatabase(): ?sqlDatabase {
		return self::unifiedConfig()->getDefaultSqlDatabase();
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getSqlDatabaseByName( string $name ): ?sqlDatabase {
		return self::unifiedConfig()->getSqlDatabaseByName( $name );
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getMicrosoft(): microsoft {
		return self::unifiedConfig()->microsoft;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getJwtAuth(): jwtAuth {
		return self::unifiedConfig()->jwtAuth;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getPayjunction(): payjunction {
		return self::unifiedConfig()->payjunction;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getAppDictionary(): array {
		return self::unifiedConfig()->appDictionary;
	}

}
