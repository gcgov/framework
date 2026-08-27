<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\services\log;

#[CoversClass(log::class)]
final class LogTest extends TestCase {

	private string $logsDir = '';

	protected function setUp(): void {
		$this->logsDir = sys_get_temp_dir() . '/gcgov-framework-tests/logs';
		if ( !is_dir( $this->logsDir ) ) {
			mkdir( $this->logsDir, 0777, true );
		}

		// Reset the static loggers cache so each test starts fresh.
		$prop = new \ReflectionProperty( log::class, 'loggers' );
		$prop->setValue( null, [] );

		// Point config::getRootDir at our temp dir's parent so logs/foo.log
		// resolves into our writable temp dir.
		$rootDir = dirname( $this->logsDir );
		$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'rootDir' );
		$prop->setValue( null, $rootDir );

		$this->setDestination( logging::DESTINATION_FILE );
	}


	/** The seeded unifiedConfig is shared across tests; put it back. */
	protected function tearDown(): void {
		$this->setDestination( logging::DESTINATION_STDERR );

		parent::tearDown();
	}


	private function setDestination( string $destination ): void {
		$prop   = new \ReflectionProperty( \gcgov\framework\config::class, 'unifiedConfig' );
		$config = $prop->getValue();
		if( $config instanceof \gcgov\framework\models\unifiedConfig ) {
			$config->logging->destination = $destination;
		}

		$loggers = new \ReflectionProperty( log::class, 'loggers' );
		$loggers->setValue( null, [] );
	}

	public function testDebugLogWritesToChannelFile(): void {
		log::debug( 'test-channel', 'hello debug', [ 'key' => 'value' ] );

		$file = $this->logsDir . '/test-channel.log';
		$this->assertFileExists( $file );
		$contents = file_get_contents( $file );
		$this->assertStringContainsString( 'hello debug', $contents );
		$this->assertStringContainsString( 'DEBUG', $contents );
	}

	public function testInfoLogWritesToChannelFile(): void {
		log::info( 'info-channel', 'info message' );
		$contents = file_get_contents( $this->logsDir . '/info-channel.log' );
		$this->assertStringContainsString( 'INFO', $contents );
	}

	public function testNoticeWarningErrorCriticalAlertEmergencyLevels(): void {
		log::notice( 'lvl-channel', 'n' );
		log::warning( 'lvl-channel', 'w' );
		log::error( 'lvl-channel', 'e' );
		log::critical( 'lvl-channel', 'c' );
		log::alert( 'lvl-channel', 'a' );
		log::emergency( 'lvl-channel', 'em' );

		$contents = file_get_contents( $this->logsDir . '/lvl-channel.log' );
		foreach ( [ 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' ] as $level ) {
			$this->assertStringContainsString( $level, $contents );
		}
	}

	public function testRepeatedCallsReuseSameLoggerInstance(): void {
		log::debug( 'reuse-channel', 'a' );
		log::debug( 'reuse-channel', 'b' );

		$prop = new \ReflectionProperty( log::class, 'loggers' );
		/** @var array<string, \Monolog\Logger> $loggers */
		$loggers = $prop->getValue();
		$this->assertCount( 1, $loggers );
		$this->assertArrayHasKey( 'reuse-channel', $loggers );
	}


	/**
	 * The v7 default. A container's filesystem does not survive a deploy, so file logs
	 * would be per-replica and destroyed on every release.
	 */
	public function testStderrIsTheDefaultDestinationAndEmitsJsonLines(): void {
		$this->setDestination( logging::DESTINATION_STDERR );

		$before = $this->logsDir . '/stderr-channel.log';
		log::error( 'stderr-channel', 'to stderr' );

		$this->assertFileDoesNotExist( $before, 'stderr destination must not write a log file' );
		$this->assertSame( logging::DESTINATION_STDERR, ( new logging() )->destination );
		$this->assertTrue( ( new logging() )->writesToStderr() );
		$this->assertFalse( ( new logging() )->writesToFile() );
	}


	public function testBothDestinationWritesTheFileAsWell(): void {
		$this->setDestination( logging::DESTINATION_BOTH );

		log::error( 'both-channel', 'to both' );

		$this->assertFileExists( $this->logsDir . '/both-channel.log' );
	}

}
