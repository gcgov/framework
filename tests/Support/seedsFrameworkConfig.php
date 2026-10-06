<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Support;

use gcgov\framework\config;
use gcgov\framework\models\unifiedConfig;

/**
 * Seed \gcgov\framework\config's static unifiedConfig for a test, and put back whatever
 * was there afterwards.
 *
 * config holds its hydrated configuration in a private static, so a test that replaces it
 * changes the configuration every later test sees. Several test classes reach in with
 * reflection and never restore, which makes their neighbours depend on PHPUnit's execution
 * order: a test relying on the bootstrap's basePath of 'api' is one alphabetical filename
 * away from failing for a reason that has nothing to do with the code under test.
 *
 * Restoring in tearDown() is the whole point — use this rather than a fresh
 * ReflectionProperty in each setUp().
 */
trait seedsFrameworkConfig {

	private static ?unifiedConfig $seedsFrameworkConfigOriginal = null;
	private static bool           $seedsFrameworkConfigCaptured = false;

	/**
	 * Install a configuration for the current test.
	 *
	 * @param  callable(unifiedConfig): void|null  $mutate  applied to a fresh unifiedConfig
	 */
	protected function seedConfig( ?callable $mutate = null ): unifiedConfig {
		$property = new \ReflectionProperty( config::class, 'unifiedConfig' );

		if( !self::$seedsFrameworkConfigCaptured ) {
			self::$seedsFrameworkConfigOriginal = $property->isInitialized() ? $property->getValue() : null;
			self::$seedsFrameworkConfigCaptured = true;
		}

		$config = new unifiedConfig();
		if( $mutate!==null ) {
			$mutate( $config );
		}
		$property->setValue( null, $config );

		return $config;
	}


	/** Put back the configuration that was installed before this test ran. */
	protected function restoreConfig(): void {
		if( !self::$seedsFrameworkConfigCaptured ) {
			return;
		}

		( new \ReflectionProperty( config::class, 'unifiedConfig' ) )->setValue( null, self::$seedsFrameworkConfigOriginal );
		self::$seedsFrameworkConfigCaptured = false;
		self::$seedsFrameworkConfigOriginal = null;
	}


	protected function tearDown(): void {
		$this->restoreConfig();
		parent::tearDown();
	}

}
