<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\config;
use gcgov\framework\models\unifiedConfig;

#[CoversClass(config::class)]
final class ConfigTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-config-test-' . uniqid();
		mkdir( $this->tempRootDir . '/app/config', 0777, true );

		$rootProp = new \ReflectionProperty( config::class, 'rootDir' );
		$rootProp->setValue( null, $this->tempRootDir );
		$appProp = new \ReflectionProperty( config::class, 'appDir' );
		$appProp->setValue( null, $this->tempRootDir . '/app' );
	}

	public function testGetAppDirReflectsConfiguredValue(): void {
		$this->assertSame( $this->tempRootDir . '/app', config::getAppDir() );
	}

	public function testGetModelsDirAppendsModels(): void {
		$this->assertSame( $this->tempRootDir . '/app/models/', config::getModelsDir() );
	}

	public function testGetConfigFilePathIsRootConfigJson(): void {
		$this->assertSame( $this->tempRootDir . '/config.json', config::getConfigFilePath() );
	}

	public function testGetServicesDirAppendsServices(): void {
		$this->assertSame( $this->tempRootDir . '/app/services/', config::getServicesDir() );
	}

	public function testGetSrvDirReturnsRootSrv(): void {
		$this->assertSame( $this->tempRootDir . '/srv/', config::getSrvDir() );
	}

	public function testGetRootDirReturnsConfiguredValue(): void {
		$this->assertSame( $this->tempRootDir, config::getRootDir() );
	}

	public function testGetTempDirIsRootSrvTmpTmp(): void {
		$this->assertSame( $this->tempRootDir . '/srv/tmp/tmp', config::getTempDir() );
	}

	public function testUnifiedConfigIsExposedThroughStaticAccessors(): void {
		$unified                 = new unifiedConfig();
		$unified->type           = 'local';
		$unified->basePath       = 'custom';
		$unified->rootUrl        = 'https://example.gov/';
		$unified->app->title     = 'Widget API';
		$unified->appDictionary  = [ 'key' => 'value' ];

		$prop = new \ReflectionProperty( config::class, 'unifiedConfig' );
		$prop->setValue( null, $unified );

		$this->assertSame( '/custom', config::getBasePath() );
		$this->assertSame( 'https://example.gov', config::getRootUrl() );
		$this->assertSame( 'https://example.gov/custom', config::getBaseUrl() );
		$this->assertTrue( config::isLocal() );
		$this->assertSame( 'Widget API', config::getApp()->title );
		$this->assertSame( [ 'key' => 'value' ], config::getAppDictionary() );
		$this->assertSame( $unified->logging, config::getLogging() );
		$this->assertSame( $unified->email, config::getEmail() );
		$this->assertSame( $unified->settings, config::getSettings() );
	}

	public function testDeprecatedPassThroughsPreserveV6CallPatterns(): void {
		$unified                                    = new unifiedConfig();
		$unified->type                              = 'prod';
		$unified->basePath                          = '/api/';
		$unified->settings->forceMfaForPasswordUsers = true;
		$unified->app->title                        = 'Widget API';

		$prop = new \ReflectionProperty( config::class, 'unifiedConfig' );
		$prop->setValue( null, $unified );

		// v6 environmentConfig call patterns
		$this->assertSame( $unified, config::getEnvironmentConfig() );
		$this->assertSame( '/api', config::getEnvironmentConfig()->getBasePath() );
		$this->assertFalse( config::getEnvironmentConfig()->isLocal() );
		$this->assertSame( [], config::getEnvironmentConfig()->mongoDatabases );

		// v6 appConfig call patterns
		$this->assertSame( $unified, config::getAppConfig() );
		$this->assertTrue( config::getAppConfig()->settings->forceMfaForPasswordUsers );
		$this->assertSame( 'Widget API', config::getAppConfig()->app->title );
		$this->assertSame( '', config::getAppConfig()->email->SMTPUsername );
	}

	public function testIsFinalClass(): void {
		$this->assertTrue( ( new \ReflectionClass( config::class ) )->isFinal() );
	}

}
