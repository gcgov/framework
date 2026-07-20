<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\application;
use gcgov\framework\cli\commands\chromeStatusCommand;
use gcgov\framework\services\chrome\chromeInstallation;
use gcgov\framework\tests\Unit\Services\Chrome\ChromeInstallationTest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(chromeStatusCommand::class)]
final class ChromeStatusCommandTest extends TestCase {

	private string $tempRootDir = '';
	private string $srvDir = '';
	private string $platform = '';

	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-chromestatus-test-' . uniqid();
		$this->srvDir      = $this->tempRootDir . '/srv';
		mkdir( $this->srvDir, 0777, true );
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );

		$this->platform = chromeInstallation::detectCurrentPlatform();
		appContext::$composerAutoloadPath = $this->tempRootDir . '/vendor/autoload.php';
	}

	protected function tearDown(): void {
		appContext::$composerAutoloadPath = null;
		chromeInstallation::deleteRecursively( $this->tempRootDir );
	}


	private function seedInstalledVersion( string $version ): string {
		$binaryDir = chromeInstallation::chromeDir( $this->srvDir ) . '/' . $version . '/chrome-headless-shell-' . $this->platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $this->platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );

		return $binaryDir . '/' . $binaryName;
	}


	private function makeCommandTester( array $mockResponses = [] ): CommandTester {
		$httpClient = new Client( [ 'handler' => HandlerStack::create( new MockHandler( $mockResponses ) ) ] );

		return new CommandTester( new chromeStatusCommand( $httpClient ) );
	}


	public function testNotInstalledExitsWithFailure(): void {
		$commandTester = $this->makeCommandTester();
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 1, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( 'not installed', $display );
		$this->assertStringContainsString( 'chrome:install', $display );
	}

	public function testInstalledShowsVersionPlatformAndPath(): void {
		$binaryPath = $this->seedInstalledVersion( '151.0.7922.34' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		$commandTester = $this->makeCommandTester();
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 0, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( '151.0.7922.34', $display );
		$this->assertStringContainsString( $this->platform, $display );
		$this->assertStringContainsString( $binaryPath, $display );
		$this->assertStringContainsString( 'installed', $display );
	}

	public function testBrokenManifestExitsWithFailureAndForceHint(): void {
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );
		// no binary on disk

		$commandTester = $this->makeCommandTester();
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 1, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( 'broken', $display );
		$this->assertStringContainsString( '--force', $display );
	}

	public function testMissingManifestFallsBackToScanWithNote(): void {
		$this->seedInstalledVersion( '151.0.7922.34' );
		// no manifest written

		$commandTester = $this->makeCommandTester();
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 0, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( '151.0.7922.34', $display );
		$this->assertStringContainsString( 'manifest is missing', $display );
	}

	public function testOtherInstalledVersionsAreListedWithUpdateHint(): void {
		$this->seedInstalledVersion( '150.0.0.1' );
		$this->seedInstalledVersion( '151.0.7922.34' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		$commandTester = $this->makeCommandTester();
		$commandTester->execute( [] );

		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( '150.0.0.1', $display );
		$this->assertStringContainsString( 'chrome:update', $display );
	}

	public function testCheckLatestUpToDate(): void {
		$this->seedInstalledVersion( '151.0.7922.34' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		$commandTester = $this->makeCommandTester( [ new Response( 200, [], ChromeInstallationTest::FIXTURE_JSON ) ] );
		$exitCode      = $commandTester->execute( [ '--check-latest' => true ] );

		$this->assertSame( 0, $exitCode );
		$this->assertStringContainsString( 'up to date', $commandTester->getDisplay() );
	}

	public function testCheckLatestUpdateAvailable(): void {
		$this->seedInstalledVersion( '100.0.0.0' );
		chromeInstallation::writeManifest( $this->srvDir, '100.0.0.0', $this->platform );

		$commandTester = $this->makeCommandTester( [ new Response( 200, [], ChromeInstallationTest::FIXTURE_JSON ) ] );
		$exitCode      = $commandTester->execute( [ '--check-latest' => true ] );

		$this->assertSame( 0, $exitCode, 'outdated-but-working installation still exits 0' );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( 'update available', $display );
		$this->assertStringContainsString( '151.0.7922.34', $display );
	}

	public function testCheckLatestNetworkFailureWarnsButExitStaysSuccess(): void {
		$this->seedInstalledVersion( '151.0.7922.34' );
		chromeInstallation::writeManifest( $this->srvDir, '151.0.7922.34', $this->platform );

		$commandTester = $this->makeCommandTester( [ new Response( 500, [], 'server error' ) ] );
		$exitCode      = $commandTester->execute( [ '--check-latest' => true ] );

		$this->assertSame( 0, $exitCode );
		$this->assertStringContainsString( 'Could not check the latest version', $commandTester->getDisplay() );
	}

	public function testCommandIsRegistered(): void {
		$this->assertTrue( ( new application() )->has( 'chrome:status' ) );
	}

}
