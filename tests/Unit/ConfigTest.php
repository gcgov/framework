<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\config;
use gcgov\framework\models\environmentConfig;

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

	public function testGetConfigDirAppendsConfig(): void {
		$this->assertSame( $this->tempRootDir . '/app/config/', config::getConfigDir() );
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

	public function testEnvironmentConfigCanBeInjectedAndReadBack(): void {
		$env = new environmentConfig();
		$env->basePath = 'custom';

		$prop = new \ReflectionProperty( config::class, 'environmentConfig' );
		$prop->setValue( null, $env );

		$this->assertSame( $env, config::getEnvironmentConfig() );
	}

	public function testIsFinalClass(): void {
		$this->assertTrue( ( new \ReflectionClass( config::class ) )->isFinal() );
	}

}
