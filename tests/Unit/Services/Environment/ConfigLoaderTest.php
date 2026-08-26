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
		$this->tempDir        = sys_get_temp_dir() . '/gcgov-configloader-test-' . uniqid();
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
	}


	public function testLoadStripsEnvironmentsSectionBeforeResolving(): void {
		// PROD_MONGO_URI is unset — the active load must succeed anyway because the
		// environments section is removed before resolution.
		$this->writeConfig( [
			'type'           => 'local',
			'mongoDatabases' => [ [ 'default' => true, 'database' => 'db', 'uri' => 'mongodb://local:27017' ] ],
			'environments'   => [ 'prod' => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'default' => true, 'database' => 'db', 'uri' => '%env(PROD_MONGO_URI)%' ] ] ] ],
		] );

		$config = configLoader::load( $this->tempDir );
		$this->assertInstanceOf( unifiedConfig::class, $config );
		$this->assertSame( 'local', $config->type );
	}


	public function testLoadThrowsWhenConfigMissing(): void {
		$this->expectException( environmentException::class );
		configLoader::load( $this->tempDir );
	}


	public function testLoadVariantEnvironmentResolvesPrefixedVariables(): void {
		putenv( 'PROD_MONGO_URI=mongodb://prod:27017/db' );
		$_ENV[ 'PROD_MONGO_URI' ] = 'mongodb://prod:27017/db';
		$this->writeConfig( [
			'type'         => 'local',
			'environments' => [ 'prod' => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'default' => true, 'database' => 'db', 'uri' => '%env(PROD_MONGO_URI)%' ] ] ] ],
		] );

		$variant = configLoader::loadVariantEnvironment( $this->tempDir, 'prod' );
		$this->assertSame( 'prod', $variant->type );
		$this->assertSame( 'mongodb://prod:27017/db', $variant->mongoDatabases[ 0 ]->uri );
	}


	public function testLoadVariantEnvironmentThrowsWhenEntryMissing(): void {
		$this->writeConfig( [ 'type' => 'local', 'environments' => [ 'staging' => [ 'type' => 'staging' ] ] ] );
		try {
			configLoader::loadVariantEnvironment( $this->tempDir, 'prod' );
			$this->fail( 'Expected environmentException' );
		}
		catch( environmentException $e ) {
			$this->assertStringContainsString( 'No "prod" entry', $e->getMessage() );
			$this->assertStringContainsString( 'staging', $e->getMessage() );
		}
	}


	public function testLoadVariantEnvironmentThrowsWhenNoEnvironmentsSection(): void {
		$this->writeConfig( [ 'type' => 'local' ] );
		$this->expectException( environmentException::class );
		configLoader::loadVariantEnvironment( $this->tempDir, 'prod' );
	}


	public function testVariantNamesListsSortedKeysWithoutResolution(): void {
		// %env references present but unset — variantNames must not resolve them.
		$this->writeConfig( [
			'type'         => 'local',
			'environments' => [
				'staging' => [ 'type' => 'staging', 'mongoDatabases' => [ [ 'uri' => '%env(STAGING_UNSET)%' ] ] ],
				'prod'    => [ 'type' => 'prod', 'mongoDatabases' => [ [ 'uri' => '%env(PROD_UNSET)%' ] ] ],
			],
		] );

		$this->assertSame( [ 'prod', 'staging' ], configLoader::variantNames( $this->tempDir ) );
	}


	public function testVariantNamesEmptyWhenNoEnvironmentsOrNoFile(): void {
		$this->assertSame( [], configLoader::variantNames( $this->tempDir ) );
		$this->writeConfig( [ 'type' => 'local' ] );
		$this->assertSame( [], configLoader::variantNames( $this->tempDir ) );
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
