<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\application;
use gcgov\framework\cli\chromeInstaller;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\commands\chromeInstallCommand;
use gcgov\framework\services\chrome\chromeInstallation;
use gcgov\framework\tests\Unit\Services\Chrome\ChromeInstallationTest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(chromeInstaller::class)]
#[CoversClass(chromeInstallCommand::class)]
final class ChromeInstallerTest extends TestCase {

	private string $tempRootDir = '';
	private string $srvDir = '';
	private string $platform = '';

	protected function setUp(): void {
		if( !extension_loaded( 'zip' ) ) {
			$this->markTestSkipped( 'ext-zip not loaded' );
		}

		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-chromeinstaller-test-' . uniqid();
		$this->srvDir      = $this->tempRootDir . '/srv';
		mkdir( $this->srvDir, 0777, true );
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );

		$this->platform = chromeInstallation::detectCurrentPlatform();
		appContext::$composerAutoloadPath = null;
	}

	protected function tearDown(): void {
		appContext::$composerAutoloadPath = null;
		chromeInstallation::deleteRecursively( $this->tempRootDir );
	}


	private function buildFakeZipBytes(): string {
		$zipPath    = $this->tempRootDir . '/fake-chrome.zip';
		$zipArchive = new \ZipArchive();
		$zipArchive->open( $zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$binaryName = str_starts_with( $this->platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		$zipArchive->addFromString( 'chrome-headless-shell-' . $this->platform . '/' . $binaryName, "#!/bin/sh\necho fake chrome" );
		$zipArchive->addFromString( 'chrome-headless-shell-' . $this->platform . '/libEGL.so', 'fake lib' );
		$zipArchive->close();

		$bytes = (string)file_get_contents( $zipPath );
		unlink( $zipPath );

		return $bytes;
	}


	/**
	 * @param \GuzzleHttp\Psr7\Response[] $responses
	 */
	private function makeInstaller( array $responses ): chromeInstaller {
		return new chromeInstaller( $this->srvDir, new Client( [ 'handler' => HandlerStack::create( new MockHandler( $responses ) ) ] ) );
	}


	private function makeIo(): SymfonyStyle {
		return new SymfonyStyle( new ArrayInput( [] ), new BufferedOutput() );
	}


	public function testFullInstallFlow(): void {
		$installer = $this->makeInstaller( [
			                                   new Response( 200, [], ChromeInstallationTest::FIXTURE_JSON ),
			                                   new Response( 200, [], $this->buildFakeZipBytes() ),
		                                   ] );

		$installedVersion = $installer->install( $this->makeIo() );

		$this->assertSame( '151.0.7922.34', $installedVersion );

		$executablePath = chromeInstallation::getExecutablePath( $this->srvDir );
		$this->assertNotNull( $executablePath );
		$this->assertFileExists( $executablePath );
		$this->assertStringContainsString( '151.0.7922.34/chrome-headless-shell-' . $this->platform, str_replace( '\\', '/', $executablePath ) );

		$manifest = chromeInstallation::readManifest( $this->srvDir );
		$this->assertNotNull( $manifest );
		$this->assertSame( '151.0.7922.34', $manifest[ 'version' ] );
		$this->assertSame( $this->platform, $manifest[ 'platform' ] );

		if( PHP_OS_FAMILY!=='Windows' ) {
			$this->assertSame( '0755', substr( sprintf( '%o', (int)fileperms( $executablePath ) ), -4 ) );
		}

		$chromeDir = chromeInstallation::chromeDir( $this->srvDir );
		$this->assertSame( [], glob( $chromeDir . '/tmp-*' ) ?: [], 'no temp artifacts may remain' );
		$this->assertFileExists( $chromeDir . '/.gitignore' );
	}

	public function testAlreadyInstalledShortCircuitsWithoutNetwork(): void {
		// seed a valid installation
		$binaryDir = chromeInstallation::chromeDir( $this->srvDir ) . '/151.0.7922.34/chrome-headless-shell-' . $this->platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $this->platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		// empty mock queue: any http request would throw
		$installer = $this->makeInstaller( [] );

		$this->assertSame( '151.0.7922.34', $installer->install( $this->makeIo() ) );
	}

	public function testUpdatePrunesOldVersions(): void {
		// seed an outdated installation
		$oldBinaryDir = chromeInstallation::chromeDir( $this->srvDir ) . '/100.0.0.0/chrome-headless-shell-' . $this->platform;
		mkdir( $oldBinaryDir, 0777, true );
		$binaryName = str_starts_with( $this->platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $oldBinaryDir . '/' . $binaryName, 'old binary' );
		chromeInstallation::writeManifest( $this->srvDir, '100.0.0.0', $this->platform );

		$installer = $this->makeInstaller( [
			                                   new Response( 200, [], ChromeInstallationTest::FIXTURE_JSON ),
			                                   new Response( 200, [], $this->buildFakeZipBytes() ),
		                                   ] );

		$installedVersion = $installer->install( $this->makeIo(), force: false, prune: true );

		$this->assertSame( '151.0.7922.34', $installedVersion );
		$this->assertDirectoryDoesNotExist( chromeInstallation::chromeDir( $this->srvDir ) . '/100.0.0.0' );
		$this->assertSame( '151.0.7922.34', chromeInstallation::readManifest( $this->srvDir )[ 'version' ] ?? null );
	}

	public function testCorruptDownloadFailsCleanly(): void {
		$installer = $this->makeInstaller( [
			                                   new Response( 200, [], ChromeInstallationTest::FIXTURE_JSON ),
			                                   new Response( 200, [], 'this is not a zip archive' ),
		                                   ] );

		try {
			$installer->install( $this->makeIo() );
			$this->fail( 'expected cliException' );
		}
		catch( cliException ) {
			// expected
		}

		$chromeDir = chromeInstallation::chromeDir( $this->srvDir );
		$this->assertDirectoryDoesNotExist( $chromeDir . '/151.0.7922.34' );
		$this->assertNull( chromeInstallation::readManifest( $this->srvDir ) );
		$this->assertSame( [], glob( $chromeDir . '/tmp-*' ) ?: [], 'temp artifacts must be cleaned up on failure' );
	}

	public function testVersionFeedFailureIsActionable(): void {
		$installer = $this->makeInstaller( [ new Response( 500, [], 'server error' ) ] );

		try {
			$installer->install( $this->makeIo() );
			$this->fail( 'expected cliException' );
		}
		catch( cliException $e ) {
			$this->assertStringContainsString( 'Chrome for Testing version feed', $e->getMessage() );
		}
	}

	public function testChromeInstallCommandReportsAlreadyInstalled(): void {
		// route appContext at the fake tree
		appContext::$composerAutoloadPath = $this->tempRootDir . '/vendor/autoload.php';

		$binaryDir = chromeInstallation::chromeDir( $this->srvDir ) . '/151.0.7922.34/chrome-headless-shell-' . $this->platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $this->platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		$commandTester = new CommandTester( new chromeInstallCommand() );
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 0, $exitCode );
		$this->assertStringContainsString( 'already installed', $commandTester->getDisplay() );
	}

	public function testChromeCommandsAreRegistered(): void {
		$application = new application();

		$this->assertTrue( $application->has( 'chrome:install' ) );
		$this->assertTrue( $application->has( 'chrome:update' ) );
	}

}
