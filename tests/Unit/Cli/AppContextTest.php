<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(appContext::class)]
final class AppContextTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-appcontext-test-' . uniqid();
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app/config', 0777, true );
		mkdir( $this->tempRootDir . '/deep/sub/dir', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );
		touch( $this->tempRootDir . '/composer.json' );
		appContext::$composerAutoloadPath = null;
	}

	protected function tearDown(): void {
		appContext::$composerAutoloadPath = null;
		$this->deleteDirectory( $this->tempRootDir );
	}

	public function testLocateFindsRootFromRootDirectory(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->assertSame( str_replace( '\\', '/', $this->tempRootDir ), $context->rootDir );
	}

	public function testLocateFindsRootFromSubdirectory(): void {
		$context = appContext::locate( $this->tempRootDir . '/deep/sub/dir' );
		$this->assertNotNull( $context );
		$this->assertSame( str_replace( '\\', '/', $this->tempRootDir ), $context->rootDir );
	}

	public function testLocateReturnsNullInBareDirectory(): void {
		$bareDir = sys_get_temp_dir() . '/gcgov-appcontext-bare-' . uniqid();
		mkdir( $bareDir );
		try {
			$this->assertNull( appContext::locate( $bareDir ) );
		} finally {
			rmdir( $bareDir );
		}
	}

	public function testComposerAutoloadPathTakesPriorityOverStartDir(): void {
		appContext::$composerAutoloadPath = $this->tempRootDir . '/vendor/autoload.php';
		$elsewhere = sys_get_temp_dir();
		$context = appContext::locate( $elsewhere );
		$this->assertNotNull( $context );
		$this->assertSame( str_replace( '\\', '/', $this->tempRootDir ), $context->rootDir );
	}

	public function testLocateScaffoldOnlyRequiresComposerJsonAndAppDir(): void {
		$scaffoldDir = sys_get_temp_dir() . '/gcgov-appcontext-scaffold-' . uniqid();
		mkdir( $scaffoldDir . '/app', 0777, true );
		touch( $scaffoldDir . '/composer.json' );
		try {
			$this->assertNull( appContext::locate( $scaffoldDir ), 'locate() must not match a scaffold without vendor/' );
			$context = appContext::locateScaffold( $scaffoldDir );
			$this->assertNotNull( $context );
			$this->assertSame( str_replace( '\\', '/', $scaffoldDir ), $context->rootDir );
		} finally {
			$this->deleteDirectory( $scaffoldDir );
		}
	}

	public function testRequireThrowsOutsideApplication(): void {
		$bareDir = sys_get_temp_dir() . '/gcgov-appcontext-bare-' . uniqid();
		mkdir( $bareDir );
		try {
			$this->expectException( cliException::class );
			appContext::require( $bareDir );
		} finally {
			rmdir( $bareDir );
		}
	}

	public function testDirectoryAccessors(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$root = str_replace( '\\', '/', $this->tempRootDir );
		$this->assertSame( $root . '/app', $context->getAppDir() );
		$this->assertSame( $root . '/app/config', $context->getConfigDir() );
		$this->assertSame( $root . '/srv', $context->getSrvDir() );
		$this->assertSame( $root . '/vendor/autoload.php', $context->getVendorAutoloadPath() );
	}

	public function testLoadConfigParsesActiveFile(): void {
		file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
			'type'           => 'prod',
			'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => 'mongodb://u:p@h:27017/widgets' ] ],
		] ) );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$environmentConfig = $context->loadConfig();
		$this->assertSame( 'prod', $environmentConfig->type );
		$this->assertCount( 1, $environmentConfig->mongoDatabases );
		$this->assertSame( 'widgets', $environmentConfig->mongoDatabases[0]->database );
	}

	public function testLoadConfigThrowsWhenMissing(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->expectException( cliException::class );
		$context->loadConfig();
	}


	public function testLoadConfigResolvesEnvVars(): void {
		$_ENV[ 'TEST_MONGO_URI' ] = 'mongodb://resolved:27017/widgets';
		putenv( 'TEST_MONGO_URI=mongodb://resolved:27017/widgets' );
		try {
			file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
				'type'           => 'prod',
				'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(TEST_MONGO_URI)%' ] ],
			] ) );
			$context = appContext::locate( $this->tempRootDir );
			$this->assertNotNull( $context );
			$environmentConfig = $context->loadConfig();
			$this->assertSame( 'mongodb://resolved:27017/widgets', $environmentConfig->mongoDatabases[ 0 ]->uri );
		}
		finally {
			unset( $_ENV[ 'TEST_MONGO_URI' ] );
			putenv( 'TEST_MONGO_URI' );
		}
	}


	public function testLoadConfigThrowsCliExceptionWhenEnvVarMissing(): void {
		unset( $_ENV[ 'TEST_MISSING_URI' ] );
		putenv( 'TEST_MISSING_URI' );
		file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
			'type'           => 'prod',
			'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(TEST_MISSING_URI)%' ] ],
		] ) );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->expectException( cliException::class );
		$context->loadConfig();
	}


	public function testLoadConfigVariantAppliesOverlay(): void {
		// Ambient value must LOSE to the overlay for an explicit variant read.
		$_ENV[ 'TEST_MONGO_URI' ] = 'mongodb://local:27017';
		putenv( 'TEST_MONGO_URI=mongodb://local:27017' );
		try {
			file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
				'type'           => '%env(default:local:TEST_APP_TYPE)%',
				'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(TEST_MONGO_URI)%' ] ],
			] ) );
			file_put_contents( $this->tempRootDir . '/prod.env', "TEST_APP_TYPE=prod\nTEST_MONGO_URI=mongodb://prod:27017\n" );
			$context = appContext::locate( $this->tempRootDir );
			$this->assertNotNull( $context );

			$prodConfig = $context->loadConfig( 'prod' );
			$this->assertSame( 'prod', $prodConfig->type );
			$this->assertSame( 'mongodb://prod:27017', $prodConfig->mongoDatabases[ 0 ]->uri );

			$activeConfig = $context->loadConfig();
			$this->assertSame( 'local', $activeConfig->type );
			$this->assertSame( 'mongodb://local:27017', $activeConfig->mongoDatabases[ 0 ]->uri );
		}
		finally {
			unset( $_ENV[ 'TEST_MONGO_URI' ] );
			putenv( 'TEST_MONGO_URI' );
		}
	}


	public function testLoadConfigVariantAmbientFillsOverlayGaps(): void {
		$_ENV[ 'TEST_MONGO_DB' ] = 'localDb';
		putenv( 'TEST_MONGO_DB=localDb' );
		try {
			file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
				'type'           => 'local',
				'mongoDatabases' => [ [ 'default' => true, 'database' => '%env(TEST_MONGO_DB)%', 'uri' => '%env(TEST_MONGO_URI)%' ] ],
			] ) );
			file_put_contents( $this->tempRootDir . '/prod.env', "TEST_MONGO_URI=mongodb://prod:27017\n" );
			$context = appContext::locate( $this->tempRootDir );
			$this->assertNotNull( $context );

			$prodConfig = $context->loadConfig( 'prod' );
			$this->assertSame( 'mongodb://prod:27017', $prodConfig->mongoDatabases[ 0 ]->uri );
			// TEST_MONGO_DB not in the overlay -> ambient value fills the gap
			$this->assertSame( 'localDb', $prodConfig->mongoDatabases[ 0 ]->database );
		}
		finally {
			unset( $_ENV[ 'TEST_MONGO_DB' ] );
			putenv( 'TEST_MONGO_DB' );
		}
	}


	public function testLoadConfigVariantThrowsWhenOverlayMissing(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local"}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		try {
			$context->loadConfig( 'prod' );
			$this->fail( 'Expected cliException' );
		}
		catch( cliException $e ) {
			$this->assertStringContainsString( 'prod.env', $e->getMessage() );
		}
	}


	public function testLoadConfigVariantMentionsMigrationWhenLegacyFileExists(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local"}' );
		file_put_contents( $this->tempRootDir . '/app/config/environment-prod.json', '{"type":"prod"}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		try {
			$context->loadConfig( 'prod' );
			$this->fail( 'Expected cliException' );
		}
		catch( cliException $e ) {
			$this->assertStringContainsString( 'environment-prod.json', $e->getMessage() );
			$this->assertStringContainsString( 'Migrating a v6 app to v7', $e->getMessage() );
		}
	}


	public function testDescribeConfigSource(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$root = str_replace( '\\', '/', $this->tempRootDir );
		$this->assertSame( $root . '/config.json', $context->describeConfigSource() );
		$this->assertSame( $root . '/config.json (overlay: ' . $root . '/prod.env)', $context->describeConfigSource( 'prod' ) );
	}


	public function testGetEnvironmentVariantsListsOverlayFiles(): void {
		touch( $this->tempRootDir . '/prod.env' );
		touch( $this->tempRootDir . '/staging.env' );
		// none of these may appear as variants: the example file, dotfiles, the
		// config itself, or a legacy app/config file
		touch( $this->tempRootDir . '/prod.env.example' );
		touch( $this->tempRootDir . '/.env' );
		touch( $this->tempRootDir . '/.env.local' );
		touch( $this->tempRootDir . '/config.json' );
		touch( $this->tempRootDir . '/app/config/environment-local.json' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->assertSame( [ 'prod', 'staging' ], $context->getEnvironmentVariants() );
	}

	private function deleteDirectory( string $directory ): void {
		if( !is_dir( $directory ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $directory );
	}

}
