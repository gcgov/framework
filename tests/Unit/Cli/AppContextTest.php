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


	public function testActiveConfigStripsEnvironmentsSection(): void {
		// The CLI-only environments section must not have to resolve for the active
		// config to load — its PROD_* variables are unset here.
		file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
			'type'           => 'local',
			'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => 'mongodb://local:27017' ] ],
			'environments'   => [ 'prod' => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(PROD_MONGO_URI)%' ] ] ] ],
		] ) );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$active = $context->loadConfig();
		$this->assertSame( 'local', $active->type );
		$this->assertSame( 'mongodb://local:27017', $active->mongoDatabases[ 0 ]->uri );
	}


	public function testLoadVariantEnvironmentResolvesEntry(): void {
		$_ENV[ 'PROD_MONGO_URI' ] = 'mongodb://prod:27017/widgets';
		putenv( 'PROD_MONGO_URI=mongodb://prod:27017/widgets' );
		try {
			file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
				'type'           => 'local',
				'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => 'mongodb://local:27017' ] ],
				'environments'   => [ 'prod' => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(PROD_MONGO_URI)%' ] ] ] ],
			] ) );
			$context = appContext::locate( $this->tempRootDir );
			$this->assertNotNull( $context );
			$prod = $context->loadVariantEnvironment( 'prod' );
			$this->assertSame( 'prod', $prod->type );
			$this->assertSame( 'mongodb://prod:27017/widgets', $prod->mongoDatabases[ 0 ]->uri );
		}
		finally {
			unset( $_ENV[ 'PROD_MONGO_URI' ] );
			putenv( 'PROD_MONGO_URI' );
		}
	}


	public function testLoadVariantEnvironmentThrowsCliExceptionWhenPrefixedVarMissing(): void {
		unset( $_ENV[ 'PROD_MONGO_URI' ] );
		putenv( 'PROD_MONGO_URI' );
		file_put_contents( $this->tempRootDir . '/config.json', json_encode( [
			'type'         => 'local',
			'environments' => [ 'prod' => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => '%env(PROD_MONGO_URI)%' ] ] ] ],
		] ) );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		try {
			$context->loadVariantEnvironment( 'prod' );
			$this->fail( 'Expected cliException' );
		}
		catch( cliException $e ) {
			$this->assertStringContainsString( 'PROD_MONGO_URI', $e->getMessage() );
		}
	}


	public function testLoadVariantEnvironmentThrowsWhenEntryMissing(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local","environments":{"staging":{"type":"staging"}}}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		try {
			$context->loadVariantEnvironment( 'prod' );
			$this->fail( 'Expected cliException' );
		}
		catch( cliException $e ) {
			$this->assertStringContainsString( 'No "prod" entry', $e->getMessage() );
			$this->assertStringContainsString( 'staging', $e->getMessage() );
		}
	}


	public function testLoadVariantEnvironmentMentionsMigrationWhenLegacyFileExists(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local"}' );
		file_put_contents( $this->tempRootDir . '/app/config/environment-prod.json', '{"type":"prod"}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		try {
			$context->loadVariantEnvironment( 'prod' );
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
		$this->assertSame( $root . '/config.json (environments.prod)', $context->describeConfigSource( 'prod' ) );
	}


	public function testGetEnvironmentVariantsListsEnvironmentsSection(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local","environments":{"prod":{"type":"prod"},"staging":{"type":"staging"}}}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->assertSame( [ 'prod', 'staging' ], $context->getEnvironmentVariants() );
	}


	public function testGetEnvironmentVariantsEmptyWithoutEnvironmentsSection(): void {
		file_put_contents( $this->tempRootDir . '/config.json', '{"type":"local"}' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->assertSame( [], $context->getEnvironmentVariants() );
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
