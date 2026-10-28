<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\tests\Support\seedsFrameworkConfig;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

#[CoversClass(log::class)]
final class MongoLogTest extends TestCase {

	use seedsFrameworkConfig;

	private string $logsDir = '';

	protected function setUp(): void {
		$this->logsDir = sys_get_temp_dir() . '/gcgov-mongo-log-tests/logs';
		if ( !is_dir( $this->logsDir ) ) {
			mkdir( $this->logsDir, 0777, true );
		}

		// Reset static loggers cache between tests.
		$prop = new \ReflectionProperty( log::class, 'loggers' );
		$prop->setValue( null, [] );

		// Configure rootDir so logs write to our temp directory.
		$rootDir = dirname( $this->logsDir );
		$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'rootDir' );
		$prop->setValue( null, $rootDir );
	}

	public function testNoOpWhenLoggingDisabled(): void {
		$this->primeEnvWithMongoLogging( false );

		log::debug( 'mongo-test', 'should not be written' );
		$this->assertFileDoesNotExist( $this->logsDir . '/mongo-test.log' );
		$this->assertSame( [], self::cachedLoggers(), 'a disabled log builds no logger, so it reaches no destination at all' );
	}

	public function testWritesAllLevelsWhenLoggingEnabled(): void {
		$this->primeEnvWithMongoLogging( true );

		log::debug( 'levels', 'd' );
		log::info( 'levels', 'i' );
		log::notice( 'levels', 'n' );
		log::warning( 'levels', 'w' );
		log::error( 'levels', 'e' );
		log::critical( 'levels', 'c' );
		log::alert( 'levels', 'a' );
		log::emergency( 'levels', 'em' );

		$contents = file_get_contents( $this->logsDir . '/levels.log' );
		foreach ( [ 'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' ] as $level ) {
			$this->assertStringContainsString( $level, $contents );
		}
	}

	public function testEmptyMongoDatabasesIsAGracefulNoOp(): void {
		// log::* methods short-circuit when mongoDatabases is empty rather
		// than trying to access the first entry. This used to raise a PHP
		// warning under PHP 8.4; the guard added during the level-5 cleanup
		// preserves silent no-op semantics.
		$this->seedConfig( static function( unifiedConfig $config ): void {
			$config->mongoDatabases = [];
		} );

		log::debug( 'empty-mongo', 'msg' );
		$this->assertFileDoesNotExist( $this->logsDir . '/empty-mongo.log' );
	}


	/**
	 * The default destination. The database layer's records go to stderr as JSON lines, the
	 * same as every other framework record, so a container's output carries them and
	 * nothing is written inside the container.
	 *
	 * Asserted against the handlers rather than by logging, as LogTest does: a record sent
	 * to stderr would print on every green run.
	 */
	public function testFollowsTheStderrDestination(): void {
		$this->primeEnvWithMongoLogging( true, logging::DESTINATION_STDERR );

		$handlers = self::logger( 'MongoService' )->getHandlers();

		$this->assertCount( 1, $handlers, 'the stderr destination adds no file handler' );
		$this->assertInstanceOf( StreamHandler::class, $handlers[ 0 ] );
		$this->assertSame( 'php://stderr', $handlers[ 0 ]->getUrl() );
		$this->assertInstanceOf( JsonFormatter::class, $handlers[ 0 ]->getFormatter() );
	}


	/** "both" is stderr plus the channel's file, as it is for services\log. */
	public function testFollowsTheBothDestination(): void {
		$this->primeEnvWithMongoLogging( true, logging::DESTINATION_BOTH );

		$urls = array_map(
			static fn( \Monolog\Handler\HandlerInterface $h ): ?string => $h instanceof StreamHandler ? $h->getUrl() : null,
			self::logger( 'MongoService' )->getHandlers()
		);

		$this->assertCount( 2, $urls );
		$this->assertContains( 'php://stderr', $urls );
		$this->assertContains( $this->logsDir . '/MongoService.log', $urls );
	}


	/**
	 * The file destination by default here: the tests above that read a file need one. A
	 * fresh unifiedConfig defaults to stderr, which writes nothing a test can read back.
	 */
	private function primeEnvWithMongoLogging( bool $enabled, string $destination = logging::DESTINATION_FILE ): void {
		$this->seedConfig( static function( unifiedConfig $config ) use ( $enabled, $destination ): void {
			$db          = new mongoDatabase();
			$db->logging = $enabled;

			$config->mongoDatabases        = [ $db ];
			$config->logging->destination = $destination;
		} );
	}


	/**
	 * Monolog opens a stream lazily, so building the logger writes nothing and creates no
	 * file. That lets the destination tests assert the wiring without emitting a record.
	 */
	private static function logger( string $channel ): Logger {
		/** @var Logger $logger */
		$logger = ( new \ReflectionMethod( log::class, 'getLogger' ) )->invoke( null, $channel );

		return $logger;
	}


	/** @return array<string, Logger> */
	private static function cachedLoggers(): array {
		/** @var array<string, Logger> $loggers */
		$loggers = ( new \ReflectionProperty( log::class, 'loggers' ) )->getValue();

		return $loggers;
	}

}
