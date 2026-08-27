<?php

namespace gcgov\framework\services\documentation\controllers;

use gcgov\framework\config;
use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;
use JetBrains\PhpStorm\NoReturn;

class documentation implements controller {

	public function __construct() {

	}


	#[NoReturn]
	public function yaml(): void {
		$scanDirectories = $this->getScanDirectories();
		$excludeFilesDirectories = $this->getExcludeDirectoriesFiles();
		$finder = new \OpenApi\SourceFinder( $scanDirectories, $excludeFilesDirectories, '*.php' );
		$openapi = ( new \OpenApi\Generator() )->generate( $finder );
		header( 'Content-Type: text/x-yaml' );
		echo $openapi->toYaml();
		die();
	}


	/**
	 * The application's own source, plus the framework's.
	 *
	 * The framework directory is derived from this file rather than guessed at
	 * vendor/gcgov/framework, so it is correct whether the framework is installed from
	 * Packagist or symlinked from a path repository during development. Under a path
	 * repository the old hardcoded vendor path did not exist and was silently dropped by
	 * the file_exists filter below, so nothing of the framework was documented in
	 * development.
	 *
	 * Scanning the framework's src/services now also picks up the Framework Services'
	 * own annotations. As separate packages they were simply never in this list, so
	 * their endpoints never reached the document.
	 *
	 * @return string[]
	 */
	private function getScanDirectories(): array {
		$frameworkSrc = dirname( __DIR__, 3 );

		$directoriesToScan = [
			config::getAppDir(),
			$frameworkSrc . '/controllers',
			$frameworkSrc . '/exceptions',
			$frameworkSrc . '/models',
			$frameworkSrc . '/services'
		];

		foreach( $directoriesToScan as $i => $directory ) {
			if( !file_exists( $directory ) ) {
				unset( $directoriesToScan[ $i ] );
			}
		}

		return array_values( $directoriesToScan );
	}


	/**
	 * An application that defines its own user model replaces the framework's, so the
	 * framework's must not also appear in the document as a second schema of the same name.
	 *
	 * @return string[]
	 */
	private function getExcludeDirectoriesFiles(): array {
		$frameworkSrc = dirname( __DIR__, 3 );

		$exclusions = [];

		$vendor = config::getRootDir() . '/vendor';
		if( file_exists( $vendor ) ) {
			$exclusions[] = $vendor;
		}

		if( class_exists( '\app\models\user' ) ) {
			$exclusions[] = $frameworkSrc . '/services/mongodb/models/auth/user.php';
		}

		if( class_exists( '\app\models\authUser' ) ) {
			$exclusions[] = $frameworkSrc . '/models/authUser.php';
		}

		return array_values( $exclusions );
	}


	public function routes(): controllerDataResponse {
		$routes = [];
		return new controllerDataResponse( $routes );
	}


	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after(): void {
	}


	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before(): void {
	}

}
