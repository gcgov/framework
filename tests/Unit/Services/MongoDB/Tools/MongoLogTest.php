<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\log;
use gcgov\framework\models\environmentConfig;
use gcgov\framework\models\config\environment\mongoDatabase;

#[CoversClass(log::class)]
final class MongoLogTest extends TestCase {

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
		$env = new environmentConfig();
		$env->mongoDatabases = [];
		$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'environmentConfig' );
		$prop->setValue( null, $env );

		log::debug( 'empty-mongo', 'msg' );
		$this->assertFileDoesNotExist( $this->logsDir . '/empty-mongo.log' );
	}

	private function primeEnvWithMongoLogging( bool $enabled ): void {
		$env = new environmentConfig();
		$db = new mongoDatabase();
		$db->logging = $enabled;
		$env->mongoDatabases = [ $db ];
		$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'environmentConfig' );
		$prop->setValue( null, $env );
	}

}
