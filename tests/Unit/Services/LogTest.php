<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\services\log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

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
	 *
	 * Asserted against the handlers rather than by logging: this test used to call
	 * log::error() and then check only that no FILE appeared, which is true of a great many
	 * broken implementations and said nothing at all about JSON lines. What it did reliably
	 * do was print a record to the console on every green run.
	 */
	public function testStderrIsTheDefaultDestinationAndEmitsJsonLines(): void {
		$this->setDestination( logging::DESTINATION_STDERR );

		$handlers = self::buildHandlers( 'stderr-channel' );

		$this->assertCount( 1, $handlers, 'the stderr destination adds no file handler' );
		$this->assertInstanceOf( StreamHandler::class, $handlers[ 0 ] );
		$this->assertSame( 'php://stderr', $handlers[ 0 ]->getUrl() );
		$this->assertInstanceOf( JsonFormatter::class, $handlers[ 0 ]->getFormatter(), 'a collector has to be able to query the records' );

		$this->assertSame( logging::DESTINATION_STDERR, ( new logging() )->destination );
		$this->assertTrue( ( new logging() )->writesToStderr() );
		$this->assertFalse( ( new logging() )->writesToFile() );
	}


	/** "both" is the stderr handler plus the file handler, not one or the other. */
	public function testBothDestinationAddsTheFileHandlerAlongsideStderr(): void {
		$this->setDestination( logging::DESTINATION_BOTH );

		$handlers = self::buildHandlers( 'both-channel' );
		$urls     = array_map( static fn( StreamHandler $h ): ?string => $h->getUrl(), $handlers );

		$this->assertCount( 2, $handlers );
		$this->assertContains( 'php://stderr', $urls );
		$this->assertContains( $this->logsDir . '/both-channel.log', $urls );
	}


	/**
	 * Monolog opens a stream lazily, so building the handlers writes nothing and creates
	 * no file — which is what lets these two tests assert the wiring without emitting a
	 * record.
	 *
	 * @return \Monolog\Handler\StreamHandler[]
	 */
	private static function buildHandlers( string $channel ): array {
		/** @var \Monolog\Handler\StreamHandler[] $handlers */
		$handlers = ( new \ReflectionMethod( log::class, 'buildHandlers' ) )->invoke( null, $channel );

		return $handlers;
	}

}
