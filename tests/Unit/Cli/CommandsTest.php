<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\application;
use gcgov\framework\cli\commands\certGenerateAuthCommand;
use gcgov\framework\cli\commands\cliListCommand;
use gcgov\framework\cli\commands\completionPowershellCommand;
use gcgov\framework\cli\commands\envCommand;
use gcgov\framework\cli\commands\setupCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(cliListCommand::class)]
#[CoversClass(certGenerateAuthCommand::class)]
#[CoversClass(completionPowershellCommand::class)]
#[CoversClass(envCommand::class)]
#[CoversClass(setupCommand::class)]
final class CommandsTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		// fake app tree; \app\app and \app\router are the tests/bootstrap.php stubs
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-commands-test-' . uniqid();
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app/config', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );
		touch( $this->tempRootDir . '/composer.json' );

		// route gf's app-root detection at the fake tree, independent of cwd
		appContext::$composerAutoloadPath = $this->tempRootDir . '/vendor/autoload.php';
	}

	protected function tearDown(): void {
		appContext::$composerAutoloadPath = null;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tempRootDir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->tempRootDir );
	}

	public function testCliListShowsCliRoutesWithDescriptions(): void {
		$commandTester = new CommandTester( new cliListCommand() );
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 0, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( '/cli/cleanup', $display );
		$this->assertStringContainsString( 'Clean up temp records', $display );
		$this->assertStringContainsString( '/cli/report', $display );
		$this->assertStringNotContainsString( '/widget', $display );
	}

	public function testEnvCommandCopiesVariantFiles(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-local.json', '{"type":"local"}' );

		$commandTester = new CommandTester( new envCommand() );
		$exitCode      = $commandTester->execute( [ 'environment' => 'local' ] );

		$this->assertSame( 0, $exitCode );
		$this->assertSame( '{"type":"local"}', file_get_contents( $this->tempRootDir . '/app/config/environment.json' ) );
		$this->assertStringContainsString( 'copied', $commandTester->getDisplay() );
	}

	public function testCertGenerateAuthCreatesKeypairsAndGuidsJson(): void {
		if( !extension_loaded( 'openssl' ) ) {
			$this->markTestSkipped( 'ext-openssl not loaded' );
		}

		$commandTester = new CommandTester( new certGenerateAuthCommand() );
		$exitCode      = $commandTester->execute( [ '--count' => '2', '--yes' => true ] );

		$this->assertSame( 0, $exitCode );

		$certificateDir = $this->tempRootDir . '/srv/jwtCertificates';
		$guids          = json_decode( (string)file_get_contents( $certificateDir . '/guids.json' ), true );
		$this->assertIsArray( $guids );
		$this->assertCount( 2, $guids );

		foreach( $guids as $guid ) {
			$this->assertFileExists( $certificateDir . '/private-' . $guid . '.pem' );
			$this->assertFileExists( $certificateDir . '/public-' . $guid . '.pem' );
			$publicKey = openssl_pkey_get_public( (string)file_get_contents( $certificateDir . '/public-' . $guid . '.pem' ) );
			$this->assertNotFalse( $publicKey, 'generated public key must be readable by openssl' );
			$privateKey = openssl_pkey_get_private( (string)file_get_contents( $certificateDir . '/private-' . $guid . '.pem' ) );
			$this->assertNotFalse( $privateKey, 'generated private key must be readable by openssl' );
		}

		$this->assertFileExists( $certificateDir . '/.gitignore', 'gitignore is copied from the jwtAuth service directory' );
	}

	public function testCertGenerateAuthRegenerationReplacesOldKeys(): void {
		if( !extension_loaded( 'openssl' ) ) {
			$this->markTestSkipped( 'ext-openssl not loaded' );
		}

		$commandTester = new CommandTester( new certGenerateAuthCommand() );
		$commandTester->execute( [ '--count' => '1', '--yes' => true ] );
		$firstKeys = glob( $this->tempRootDir . '/srv/jwtCertificates/*.pem' );

		$commandTester->execute( [ '--count' => '1', '--yes' => true ] );
		$secondKeys = glob( $this->tempRootDir . '/srv/jwtCertificates/*.pem' );

		$this->assertCount( 2, (array)$firstKeys );
		$this->assertCount( 2, (array)$secondKeys );
		$this->assertNotSame( $firstKeys, $secondKeys );
	}

	public function testCompletionPowershellPrintsBridgeWithApiVersion(): void {
		$commandTester = new CommandTester( new completionPowershellCommand() );
		$exitCode      = $commandTester->execute( [] );

		$this->assertSame( 0, $exitCode );
		$display = $commandTester->getDisplay();
		$this->assertStringContainsString( 'Register-ArgumentCompleter', $display );
		$this->assertStringContainsString( '_complete', $display );
		$this->assertStringNotContainsString( '{{API_VERSION}}', $display );
		$this->assertStringContainsString( '-a' . \Symfony\Component\Console\Command\CompleteCommand::COMPLETION_API_VERSION, $display );
	}

	public function testSetupRefusesNonInteractiveMode(): void {
		$commandTester = new CommandTester( new setupCommand() );

		$this->expectException( \gcgov\framework\cli\cliException::class );
		$commandTester->execute( [], [ 'interactive' => false ] );
	}

	public function testSetupBuildReplacementTableDerivesUrlTokens(): void {
		$setupCommand = new setupCommand();

		$replacements = $setupCommand->buildReplacementTable( [
			'app_title'          => 'Widget API',
			'app_base_path'      => 'api',
			'prod_app_base_path' => '/api/',
			'app_root_url'       => 'https://local.example.gov/',
			'prod_app_absolute_path' => 'E:\Web\api\\',
		], '/var/www/widget' );

		$this->assertSame( 'Widget API', $replacements[ '{app_title}' ] );
		$this->assertSame( '/api/', $replacements[ '{app_base_path}' ] );
		$this->assertSame( 'api/', $replacements[ '{app_relative_url}' ] );
		$this->assertSame( '/api/', $replacements[ '{prod_app_base_path}' ] );
		$this->assertSame( 'api/', $replacements[ '{prod_app_relative_url}' ] );
		$this->assertSame( 'https://local.example.gov', $replacements[ '{app_root_url}' ] );
		$this->assertSame( 'E:\Web\api', $replacements[ '{prod_app_absolute_path}' ] );
		$this->assertSame( '/var/www/widget', $replacements[ '{app_absolute_path}' ] );
		$this->assertNotSame( '', $replacements[ '{app_guid}' ] );
	}

	public function testDynamicRouteCompletionSuggestsCliRoutes(): void {
		$suggestions = \gcgov\framework\cli\commands\cliCommand::suggestCliRoutes( \Symfony\Component\Console\Completion\CompletionInput::fromTokens( [ 'gf', 'cli', '' ], 2 ) );

		$values = array_map( fn( $suggestion ) => (string)$suggestion, $suggestions );
		$this->assertContains( '/cli/cleanup', $values );
		$this->assertContains( '/cli/report', $values );
	}

	public function testApplicationRunsBareListInsideFakeApp(): void {
		$application = new application();
		$application->setAutoExit( false );

		$commandTester = new \Symfony\Component\Console\Tester\ApplicationTester( $application );
		$exitCode      = $commandTester->run( [ 'command' => 'list' ] );

		$this->assertSame( 0, $exitCode );
		$this->assertStringContainsString( 'cli:list', $commandTester->getDisplay() );
	}

}
