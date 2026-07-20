<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\cliException;
use gcgov\framework\cli\phpProcess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(phpProcess::class)]
final class PhpProcessTest extends TestCase {

	public function testXdebugFlags(): void {
		$this->assertSame( [
			'-dxdebug.mode=debug',
			'-dxdebug.start_with_request=yes',
			'-dxdebug.client_host=127.0.0.1',
			'-dxdebug.client_port=9003',
		], phpProcess::xdebugFlags() );

		$this->assertContains( '-dxdebug.client_port=9999', phpProcess::xdebugFlags( 'devbox', 9999 ) );
	}

	public function testFindPhpBinaryFallsBackToRunningInterpreter(): void {
		$binary = phpProcess::findPhpBinary();
		$this->assertNotSame( '', $binary );
		$this->assertFileExists( $binary );
	}

	public function testFindPhpBinaryUsesExplicitOption(): void {
		$this->assertSame( PHP_BINARY, phpProcess::findPhpBinary( PHP_BINARY ) );
	}

	public function testFindPhpBinaryResolvesDirectory(): void {
		$tempDir = sys_get_temp_dir() . '/gcgov-phpprocess-test-' . uniqid();
		mkdir( $tempDir );
		touch( $tempDir . '/php' );
		try {
			$this->assertSame( $tempDir . '/php', phpProcess::findPhpBinary( $tempDir ) );
		} finally {
			unlink( $tempDir . '/php' );
			rmdir( $tempDir );
		}
	}

	public function testFindPhpBinaryThrowsOnBadPath(): void {
		$this->expectException( cliException::class );
		phpProcess::findPhpBinary( '/nonexistent/php-binary-path' );
	}

}
