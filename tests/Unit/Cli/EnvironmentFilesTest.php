<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\cliException;
use gcgov\framework\cli\environmentFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(environmentFiles::class)]
final class EnvironmentFilesTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-envfiles-test-' . uniqid();
		mkdir( $this->tempRootDir . '/app/config', 0777, true );
		mkdir( $this->tempRootDir . '/www', 0777, true );
	}

	protected function tearDown(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tempRootDir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->tempRootDir );
	}

	public function testAppliesAllThreeVariantFiles(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-local.json', '{"type":"local"}' );
		file_put_contents( $this->tempRootDir . '/composer-local.json', '{"name":"local"}' );
		file_put_contents( $this->tempRootDir . '/www/web-local.config', '<local/>' );

		$results = environmentFiles::apply( $this->tempRootDir, 'local' );

		$this->assertCount( 3, $results );
		$this->assertSame( '{"type":"local"}', file_get_contents( $this->tempRootDir . '/app/config/environment.json' ) );
		$this->assertSame( '{"name":"local"}', file_get_contents( $this->tempRootDir . '/composer.json' ) );
		$this->assertSame( '<local/>', file_get_contents( $this->tempRootDir . '/www/web.config' ) );
	}

	public function testMissingVariantsAreSkippedGracefully(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-prod.json', '{"type":"prod"}' );

		$results = environmentFiles::apply( $this->tempRootDir, 'prod' );

		$statuses = array_column( $results, 'status' );
		$this->assertSame( 'copied', $statuses[0] );
		$this->assertStringStartsWith( 'skipped', $statuses[1] );
		$this->assertStringStartsWith( 'skipped', $statuses[2] );
		$this->assertFileDoesNotExist( $this->tempRootDir . '/composer.json' );
	}

	public function testThrowsWhenNoVariantFileExists(): void {
		$this->expectException( cliException::class );
		environmentFiles::apply( $this->tempRootDir, 'staging' );
	}

	public function testDryRunDoesNotWrite(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-local.json', '{"type":"local"}' );

		$results = environmentFiles::apply( $this->tempRootDir, 'local', true );

		$this->assertSame( 'would copy', $results[0][ 'status' ] );
		$this->assertFileDoesNotExist( $this->tempRootDir . '/app/config/environment.json' );
	}

	public function testExistingCanonicalFilesAreOverwritten(): void {
		file_put_contents( $this->tempRootDir . '/app/config/environment-prod.json', '{"type":"prod"}' );
		file_put_contents( $this->tempRootDir . '/app/config/environment.json', '{"type":"local"}' );

		environmentFiles::apply( $this->tempRootDir, 'prod' );

		$this->assertSame( '{"type":"prod"}', file_get_contents( $this->tempRootDir . '/app/config/environment.json' ) );
	}

}
