<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Environment;

use gcgov\framework\services\environment\dotEnvLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(dotEnvLoader::class)]
final class DotEnvLoaderTest extends TestCase {

	/** @var array<string, string> */
	private array $envSnapshot = [];

	/** @var array<string, mixed> */
	private array $serverSnapshot = [];

	private string $tempDir = '';

	/** @var string[] */
	private array $introducedKeys = [ 'DOTENV_TEST_A', 'DOTENV_TEST_B', 'DOTENV_TEST_REAL' ];


	protected function setUp(): void {
		$this->envSnapshot    = $_ENV;
		$this->serverSnapshot = $_SERVER;
		$this->tempDir        = sys_get_temp_dir() . '/gcgov-dotenv-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );
		dotEnvLoader::resetForTesting();
		foreach( $this->introducedKeys as $key ) {
			putenv( $key );
			unset( $_ENV[ $key ], $_SERVER[ $key ] );
		}
	}


	protected function tearDown(): void {
		foreach( $this->introducedKeys as $key ) {
			putenv( $key );
			unset( $_ENV[ $key ], $_SERVER[ $key ] );
		}
		$_ENV    = $this->envSnapshot;
		$_SERVER = $this->serverSnapshot;
		dotEnvLoader::resetForTesting();

		$this->deleteDirectory( $this->tempDir );
	}


	public function testLoadsEnvFile(): void {
		file_put_contents( $this->tempDir . '/.env', "DOTENV_TEST_A=from_env\n" );
		dotEnvLoader::loadOnce( $this->tempDir );
		$this->assertSame( 'from_env', $_ENV[ 'DOTENV_TEST_A' ] ?? null );
		$this->assertSame( 'from_env', getenv( 'DOTENV_TEST_A' ) );
	}


	public function testRealEnvironmentWins(): void {
		putenv( 'DOTENV_TEST_REAL=real_value' );
		$_ENV[ 'DOTENV_TEST_REAL' ] = 'real_value';
		file_put_contents( $this->tempDir . '/.env', "DOTENV_TEST_REAL=dotenv_value\n" );
		dotEnvLoader::loadOnce( $this->tempDir );
		$this->assertSame( 'real_value', $_ENV[ 'DOTENV_TEST_REAL' ] );
	}


	public function testLocalOverridesBaseEnv(): void {
		file_put_contents( $this->tempDir . '/.env', "DOTENV_TEST_B=base\n" );
		file_put_contents( $this->tempDir . '/.env.local', "DOTENV_TEST_B=local\n" );
		dotEnvLoader::loadOnce( $this->tempDir );
		$this->assertSame( 'local', $_ENV[ 'DOTENV_TEST_B' ] );
	}


	public function testIsIdempotent(): void {
		file_put_contents( $this->tempDir . '/.env', "DOTENV_TEST_A=first\n" );
		dotEnvLoader::loadOnce( $this->tempDir );
		// Rewriting the file and reloading must NOT change the already-loaded value.
		file_put_contents( $this->tempDir . '/.env', "DOTENV_TEST_A=second\n" );
		dotEnvLoader::loadOnce( $this->tempDir );
		$this->assertSame( 'first', $_ENV[ 'DOTENV_TEST_A' ] );
	}


	public function testNoOpWhenAbsent(): void {
		// No .env file present — must not throw and must not set anything.
		dotEnvLoader::loadOnce( $this->tempDir );
		$this->assertArrayNotHasKey( 'DOTENV_TEST_A', $_ENV );
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
