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
		$command = phpProcess::findPhpBinary();
		$this->assertNotSame( [], $command );
		$this->assertFileExists( $command[ 0 ] );
	}

	public function testFindPhpBinaryUsesExplicitOption(): void {
		$this->assertSame( [ PHP_BINARY ], phpProcess::findPhpBinary( PHP_BINARY ) );
	}

	public function testFindPhpBinaryResolvesDirectory(): void {
		$tempDir = sys_get_temp_dir() . '/gcgov-phpprocess-test-' . uniqid();
		mkdir( $tempDir );
		touch( $tempDir . '/php' );
		try {
			$this->assertSame( [ $tempDir . '/php' ], phpProcess::findPhpBinary( $tempDir ) );
		} finally {
			unlink( $tempDir . '/php' );
			rmdir( $tempDir );
		}
	}

	public function testFindPhpBinaryParsesBinaryWithArguments(): void {
		$tempDir = sys_get_temp_dir() . '/gcgov-phpprocess-test-' . uniqid();
		mkdir( $tempDir );
		$binary = $tempDir . '/php.exe';
		$iniFile = $tempDir . '/php.ini';
		touch( $binary );
		touch( $iniFile );
		try {
			$this->assertSame(
				[ $binary, '-c', $iniFile ],
				phpProcess::findPhpBinary( $binary . ' -c ' . $iniFile )
			);
		} finally {
			unlink( $binary );
			unlink( $iniFile );
			rmdir( $tempDir );
		}
	}

	public function testFindPhpBinaryParsesQuotedBinaryWithSpaces(): void {
		$tempDir = sys_get_temp_dir() . '/gcgov phpprocess test ' . uniqid();
		mkdir( $tempDir );
		$binary = $tempDir . '/php.exe';
		touch( $binary );
		try {
			$this->assertSame(
				[ $binary, '-n' ],
				phpProcess::findPhpBinary( '"' . $binary . '" -n' )
			);
		} finally {
			unlink( $binary );
			rmdir( $tempDir );
		}
	}

	public function testFindPhpBinaryThrowsOnBadPath(): void {
		$this->expectException( cliException::class );
		phpProcess::findPhpBinary( '/nonexistent/php-binary-path' );
	}

	public function testFindPhpBinaryThrowsWhenBinaryWithArgumentsNotFound(): void {
		$this->expectException( cliException::class );
		phpProcess::findPhpBinary( '/nonexistent/php.exe -c /nonexistent/php.ini' );
	}

}
