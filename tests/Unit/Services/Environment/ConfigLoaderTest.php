<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Environment;

use gcgov\framework\models\unifiedConfig;
use gcgov\framework\services\environment\configLoader;
use gcgov\framework\services\environment\dotEnvLoader;
use gcgov\framework\services\environment\environmentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(configLoader::class)]
final class ConfigLoaderTest extends TestCase {

	/** @var array<string, string> */
	private array $envSnapshot = [];

	/** @var array<string, mixed> */
	private array $serverSnapshot = [];

	private string $tempDir = '';


	protected function setUp(): void {
		$this->envSnapshot    = $_ENV;
		$this->serverSnapshot = $_SERVER;
		// Forward slashes: configFilePath() normalises separators, so a raw sys_get_temp_dir()
		// makes the fixture disagree with it on Windows and nowhere else.
		$this->tempDir        = str_replace( '\\', '/', sys_get_temp_dir() ) . '/gcgov-configloader-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );
		dotEnvLoader::resetForTesting();
	}


	protected function tearDown(): void {
		foreach( array_keys( $_ENV ) as $key ) {
			if( !array_key_exists( $key, $this->envSnapshot ) ) {
				putenv( $key );
			}
		}
		$_ENV    = $this->envSnapshot;
		$_SERVER = $this->serverSnapshot;
		dotEnvLoader::resetForTesting();
		$this->deleteDirectory( $this->tempDir );
	}


	private function writeConfig( array $config ): void {
		file_put_contents( $this->tempDir . '/config.json', json_encode( $config ) );
	}


	public function testConfigFilePathIsRootConfigJson(): void {
		$this->assertSame( $this->tempDir . '/config.json', configLoader::configFilePath( $this->tempDir ) );
		$this->assertSame( 'config.json', configLoader::FILE_NAME );

		// Stated rather than left to the platform: the assertion above cannot exercise the
		// normalisation on a system whose temp path has no backslashes to normalise.
		$this->assertSame( 'C:/app/config.json', configLoader::configFilePath( 'C:\\app' ) );
		$this->assertSame( 'C:/app/config.json', configLoader::configFilePath( 'C:\\app\\' ) );
	}




	public function testLoadThrowsWhenConfigMissing(): void {
		$this->expectException( environmentException::class );
		configLoader::load( $this->tempDir );
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
