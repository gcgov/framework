<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\dbRestoreCommand;
use gcgov\framework\models\config\environment\mongoDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(dbRestoreCommand::class)]
final class DbRestoreCommandTest extends TestCase {

	private function makeDatabase( string $database, string $uri, bool $default = false ): mongoDatabase {
		$mongoDatabase           = new mongoDatabase();
		$mongoDatabase->database = $database;
		$mongoDatabase->uri      = $uri;
		$mongoDatabase->default  = $default;

		return $mongoDatabase;
	}

	public function testPairDatabasesMatchesByName(): void {
		$source = [ $this->makeDatabase( 'widgets', 'mongodb://prod/widgets' ), $this->makeDatabase( 'audit', 'mongodb://prod/audit' ) ];
		$target = [ $this->makeDatabase( 'audit', 'mongodb://local/audit' ), $this->makeDatabase( 'widgets', 'mongodb://local/widgets' ) ];

		$pairs = dbRestoreCommand::pairDatabases( $source, $target );

		$this->assertCount( 2, $pairs[ 'matched' ] );
		$this->assertSame( [], $pairs[ 'unmatched' ] );
		$this->assertSame( 'mongodb://local/widgets', $pairs[ 'matched' ][0][1]->uri );
	}

	public function testPairDatabasesFallsBackToDefaults(): void {
		$source = [ $this->makeDatabase( 'appProd', 'mongodb://prod/appProd', true ) ];
		$target = [ $this->makeDatabase( 'appLocal', 'mongodb://local/appLocal', true ) ];

		$pairs = dbRestoreCommand::pairDatabases( $source, $target );

		$this->assertCount( 1, $pairs[ 'matched' ] );
		$this->assertSame( 'appLocal', $pairs[ 'matched' ][0][1]->database );
	}

	public function testPairDatabasesReportsUnmatched(): void {
		$source = [ $this->makeDatabase( 'reports', 'mongodb://prod/reports' ) ];
		$target = [ $this->makeDatabase( 'widgets', 'mongodb://local/widgets' ) ];

		$pairs = dbRestoreCommand::pairDatabases( $source, $target );

		$this->assertSame( [], $pairs[ 'matched' ] );
		$this->assertSame( [ 'reports' ], $pairs[ 'unmatched' ] );
	}

	public function testPairDatabasesHonorsDbFilter(): void {
		$source = [ $this->makeDatabase( 'widgets', 'u' ), $this->makeDatabase( 'audit', 'u' ) ];
		$target = [ $this->makeDatabase( 'widgets', 'u' ), $this->makeDatabase( 'audit', 'u' ) ];

		$pairs = dbRestoreCommand::pairDatabases( $source, $target, [ 'audit' ] );

		$this->assertCount( 1, $pairs[ 'matched' ] );
		$this->assertSame( 'audit', $pairs[ 'matched' ][0][0]->database );
	}

	public function testBuildDumpCommand(): void {
		$sourceDb = $this->makeDatabase( 'widgets', 'mongodb://u:p@prod:27017/widgets' );

		$this->assertSame(
			[ '/usr/bin/mongodump', '--uri=mongodb://u:p@prod:27017/widgets', '--db=widgets', '--out=/tmp/dump' ],
			dbRestoreCommand::buildDumpCommand( '/usr/bin/mongodump', $sourceDb, '/tmp/dump' )
		);
	}

	public function testBuildRestoreCommandSameNameHasNoNsRemap(): void {
		$sourceDb = $this->makeDatabase( 'widgets', 'mongodb://prod/widgets' );
		$targetDb = $this->makeDatabase( 'widgets', 'mongodb://local/widgets' );

		$this->assertSame(
			[ '/usr/bin/mongorestore', '--uri=mongodb://local/widgets', '--drop', '/tmp/dump/widgets' ],
			dbRestoreCommand::buildRestoreCommand( '/usr/bin/mongorestore', $sourceDb, $targetDb, '/tmp/dump' )
		);
	}

	public function testBuildRestoreCommandRemapsDifferingNames(): void {
		$sourceDb = $this->makeDatabase( 'appProd', 'mongodb://prod/appProd' );
		$targetDb = $this->makeDatabase( 'appLocal', 'mongodb://local/appLocal' );

		$this->assertSame(
			[ '/usr/bin/mongorestore', '--uri=mongodb://local/appLocal', '--drop', '--nsFrom=appProd.*', '--nsTo=appLocal.*', '/tmp/dump/appProd' ],
			dbRestoreCommand::buildRestoreCommand( '/usr/bin/mongorestore', $sourceDb, $targetDb, '/tmp/dump' )
		);
	}

}
