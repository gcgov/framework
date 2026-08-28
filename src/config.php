<?php

namespace gcgov\framework;


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
		return \gcgov\framework\services\environment\configLoader::configFilePath( self::getRootDir() );
	}


	/**
	 * @deprecated v7 — the app/config directory no longer exists (configuration is the
	 *             single {root}/config.json; see getConfigFilePath()). Kept so v6 code
	 *             that located files under app/config keeps resolving the same path.
	 */
	#[\JetBrains\PhpStorm\Deprecated( reason: 'v7: configuration is the single {root}/config.json', replacement: '\gcgov\framework\config::getConfigFilePath()' )]
	public static function getConfigDir(): string {
		return self::getAppDir() . '/config/';
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
		try {
			self::$unifiedConfig = \gcgov\framework\services\environment\configLoader::load( self::getRootDir() );
		}
		catch( \gcgov\framework\services\environment\environmentException $e ) {
			throw new \gcgov\framework\exceptions\configException( $e->getMessage(), 500, $e );
		}
	}


	// --- deprecated v6 pass-throughs (migration aids) ---

	/**
	 * @deprecated v7 — use the flattened static accessors instead: `config::getEnvironmentConfig()->getBasePath()`
	 *             becomes `config::getBasePath()`, `->mongoDatabases` becomes `config::getMongoDatabases()`, etc.
	 *             Returns the unified config object, which carries every former environmentConfig field and helper,
	 *             so existing call sites keep working until they migrate.
	 * @throws \gcgov\framework\exceptions\configException
	 */
	#[\JetBrains\PhpStorm\Deprecated( reason: 'v7: config values are exposed directly on config', replacement: '\gcgov\framework\config' )]
	public static function getEnvironmentConfig(): unifiedConfig {
		return self::unifiedConfig();
	}


	/**
	 * @deprecated v7 — use the flattened static accessors instead: `config::getAppConfig()->settings` becomes
	 *             `config::getSettings()`, `->app` becomes `config::getApp()`, `->email` becomes `config::getEmail()`.
	 *             Returns a v6-shaped VIEW (app/email/settings only) over the unified config, so existing
	 *             call sites — including ones that serialize the object — keep their exact v6 behavior.
	 * @throws \gcgov\framework\exceptions\configException
	 */
	#[\JetBrains\PhpStorm\Deprecated( reason: 'v7: config values are exposed directly on config', replacement: '\gcgov\framework\config' )]
	public static function getAppConfig(): \gcgov\framework\models\appConfig {
		return new \gcgov\framework\models\appConfig( self::unifiedConfig() );
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


	/**
	 * The base path in the form a route pattern is built from: '' at the domain root, '/api' otherwise.
	 * Use this, not getBasePath(), when prefixing a route — see {@see unifiedConfig::getRoutePrefix()}.
	 *
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getRoutePrefix(): string {
		return self::unifiedConfig()->getRoutePrefix();
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


	/** Token issuer, defaulting to the application's root url. @throws \gcgov\framework\exceptions\configException */
	public static function getTokenIssuedBy(): string {
		return self::unifiedConfig()->getTokenIssuedBy();
	}


	/** Token audience, defaulting to the application's base path. @throws \gcgov\framework\exceptions\configException */
	public static function getTokenPermittedFor(): string {
		return self::unifiedConfig()->getTokenPermittedFor();
	}


	/**
	 * Directory holding the JWT signing keypairs — the configured jwtAuth.keyPath, or
	 * the default {root}/srv/jwtCertificates. Always returned with a trailing slash.
	 *
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getJwtKeyPath(): string {
		return self::unifiedConfig()->getJwtKeyPath( self::getSrvDir() );
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getPayjunction(): payjunction {
		return self::unifiedConfig()->payjunction;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getAppDictionary(): array {
		return self::unifiedConfig()->appDictionary;
	}


	/**
	 * Which Framework Services this application runs. A service whose block is absent is
	 * not constructed and contributes no routes.
	 *
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getServices(): services {
		return self::unifiedConfig()->services;
	}


	/** @throws \gcgov\framework\exceptions\configException */
	public static function getCronMonitor(): cronMonitor {
		return self::unifiedConfig()->cronMonitor;
	}

}
