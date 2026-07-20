<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Chrome;

use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use gcgov\framework\services\chrome\chrome;
use gcgov\framework\services\chrome\chromeInstallation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(chrome::class)]
final class ChromeServiceTest extends TestCase {

	private string $tempSrvDir = '';

	protected function setUp(): void {
		$this->tempSrvDir = sys_get_temp_dir() . '/gcgov-chromeservice-test-' . uniqid() . '/srv';
		mkdir( $this->tempSrvDir, 0777, true );

		// point config::getSrvDir() at the fixture (same technique tests/bootstrap.php
		// uses to seed environmentConfig)
		$srvDirProperty = new \ReflectionProperty( config::class, 'srvDir' );
		$srvDirProperty->setValue( null, $this->tempSrvDir . '/' );
	}

	protected function tearDown(): void {
		$srvDirProperty = new \ReflectionProperty( config::class, 'srvDir' );
		$srvDirProperty->setValue( null, '' );
		chromeInstallation::deleteRecursively( dirname( $this->tempSrvDir ) );
	}

	public function testGetExecutablePathThrowsWithInstallHintWhenNotInstalled(): void {
		try {
			chrome::getExecutablePath();
			$this->fail( 'expected serviceException' );
		}
		catch( serviceException $e ) {
			$this->assertStringContainsString( 'chrome:install', $e->getMessage() );
		}
	}

	public function testGetExecutablePathReturnsInstalledBinary(): void {
		$platform  = chromeInstallation::detectCurrentPlatform();
		$binaryDir = chromeInstallation::chromeDir( $this->tempSrvDir ) . '/151.0.7922.34/chrome-headless-shell-' . $platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );
		chromeInstallation::writeManifest( $this->tempSrvDir, '151.0.7922.34', $platform );

		$this->assertSame( $binaryDir . '/' . $binaryName, chrome::getExecutablePath() );
	}

	public function testGetBrowserFactoryIsBoundToInstalledBinary(): void {
		$platform  = chromeInstallation::detectCurrentPlatform();
		$binaryDir = chromeInstallation::chromeDir( $this->tempSrvDir ) . '/151.0.7922.34/chrome-headless-shell-' . $platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );
		chromeInstallation::writeManifest( $this->tempSrvDir, '151.0.7922.34', $platform );

		$browserFactory = chrome::getBrowserFactory();

		$this->assertInstanceOf( \HeadlessChromium\BrowserFactory::class, $browserFactory );
	}

	public function testGetBrowserFactoryThrowsWhenNotInstalled(): void {
		$this->expectException( serviceException::class );
		chrome::getBrowserFactory();
	}

}
