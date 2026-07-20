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

	public function testLoadEnvironmentConfigParsesVariantFile(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-prod.json', json_encode( [
			'type'           => 'prod',
			'mongoDatabases' => [ [ 'default' => true, 'database' => 'widgets', 'uri' => 'mongodb://u:p@h:27017/widgets' ] ],
		] ) );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$environmentConfig = $context->loadEnvironmentConfig( 'prod' );
		$this->assertSame( 'prod', $environmentConfig->type );
		$this->assertCount( 1, $environmentConfig->mongoDatabases );
		$this->assertSame( 'widgets', $environmentConfig->mongoDatabases[0]->database );
	}

	public function testLoadEnvironmentConfigThrowsWhenMissing(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->expectException( cliException::class );
		$context->loadEnvironmentConfig();
	}

	public function testGetEnvironmentVariantsListsVariantFiles(): void {
		touch( $this->tempRootDir . '/app/config/environment-local.json' );
		touch( $this->tempRootDir . '/app/config/environment-prod.json' );
		touch( $this->tempRootDir . '/app/config/environment.json' );
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );
		$this->assertSame( [ 'local', 'prod' ], $context->getEnvironmentVariants() );
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
