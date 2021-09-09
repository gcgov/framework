<?php


namespace gcgov\framework;


use gcgov\framework\models\appConfig;
use gcgov\framework\models\environmentConfig;


final class config {

	private static string            $rootDir = '';

	private static string            $appDir  = '';

	private static appConfig         $appConfig;

	private static environmentConfig $environmentConfig;


	public static function getTempDir() : string {
		if( self::$rootDir === '' ) {
			self::setRootDir();
		}

		return self::$rootDir.'/srv/tmp/tmp';
	}

	public static function getRootDir() : string {
		if( self::$rootDir === '' ) {
			self::setRootDir();
		}

		return self::$rootDir;
	}


	private static function setRootDir() : void {
		$appDir        = self::getAppDir();
		self::$rootDir = substr($appDir, 0, strrpos($appDir, '/'));
	}


	public static function getAppDir() : string {
		if( self::$appDir === '' ) {
			self::setAppDir();
		}

		return self::$appDir;
	}


	private static function setAppDir() : void {
		$appClass     = new \ReflectionClass( '\app\app' );
		$appDir = rtrim( dirname( $appClass->getFileName() ), '/\\' );
		$nixAppDir = str_replace('\\', '/', $appDir);
		self::$appDir = $nixAppDir;
	}


	/**
	 * @return \gcgov\framework\models\appConfig
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getAppConfig() : appConfig {
		if(!isset(self::$appConfig)) {
			self::setAppConfig();
		}
		return self::$appConfig;
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	private static function setAppConfig() {
		$appDir          = self::getAppDir();
		$appConfigFile   = $appDir . '/config/app.json';
		self::$appConfig = appConfig::jsonDeserialize( file_get_contents( $appConfigFile ) );

	}


	/**
	 * @return \gcgov\framework\models\environmentConfig
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public static function getEnvironmentConfig() : environmentConfig {
		if(!isset(self::$environmentConfig)) {
			self::setEnvironmentConfig();
		}
		return self::$environmentConfig;
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	private static function setEnvironmentConfig() {
		$appDir                  = self::getAppDir();
		$environmentConfigFile   = $appDir . '/config/environment.json';
		self::$environmentConfig = environmentConfig::jsonDeserialize( file_get_contents( $environmentConfigFile ) );
	}


}