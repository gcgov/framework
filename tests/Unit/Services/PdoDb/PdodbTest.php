<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\PdoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\pdodb\pdodb;
use gcgov\framework\models\environmentConfig;
use gcgov\framework\models\config\environment\sqlDatabase;
use gcgov\framework\models\config\environment\sqlDatabaseUser;

#[CoversClass(pdodb::class)]
final class PdodbTest extends TestCase {

	private string $sqliteFile = '';

	protected function setUp(): void {
		$this->sqliteFile = sys_get_temp_dir() . '/gcgov-pdodb-' . uniqid() . '.sqlite';
	}

	protected function tearDown(): void {
		if ( file_exists( $this->sqliteFile ) ) {
			unlink( $this->sqliteFile );
		}
	}

	public function testThrowsWhenNoDatabasesConfigured(): void {
		$this->primeEnvWith( [] );
		$this->expectException( \PDOException::class );
		new pdodb();
	}

	public function testThrowsWhenNoDefaultConfigured(): void {
		$db = $this->makeSqlDatabase( 'sqlite:' . $this->sqliteFile, 'other', false );
		$this->primeEnvWith( [ $db ] );

		try {
			new pdodb();
			$this->fail( 'Expected PDOException' );
		}
		catch ( \PDOException $e ) {
			$this->assertStringContainsString( 'default database', $e->getMessage() );
		}
	}

	public function testThrowsWhenNamedDatabaseNotFound(): void {
		$db = $this->makeSqlDatabase( 'sqlite:' . $this->sqliteFile, 'primary', true );
		$this->primeEnvWith( [ $db ] );

		try {
			new pdodb( true, 'missing' );
			$this->fail( 'Expected PDOException' );
		}
		catch ( \PDOException $e ) {
			$this->assertStringContainsString( 'missing', $e->getMessage() );
		}
	}

	public function testConnectsToDefaultDatabaseInReadOnlyMode(): void {
		$db = $this->makeSqlDatabase( 'sqlite:' . $this->sqliteFile, 'primary', true );
		$this->primeEnvWith( [ $db ] );

		$conn = new pdodb();
		$this->assertInstanceOf( \PDO::class, $conn );
	}

	public function testConnectsToNamedDatabaseWithWriteAccount(): void {
		$db = $this->makeSqlDatabase( 'sqlite:' . $this->sqliteFile, 'secondary', false );
		$this->primeEnvWith( [ $db ] );

		$conn = new pdodb( false, 'secondary' );
		$this->assertInstanceOf( \PDO::class, $conn );
	}

	private function makeSqlDatabase( string $dsn, string $name, bool $default ): sqlDatabase {
		$db = new sqlDatabase();
		$db->name = $name;
		$db->default = $default;
		$db->dsn = $dsn;
		$db->readAccount = new sqlDatabaseUser();
		$db->writeAccount = new sqlDatabaseUser();
		return $db;
	}

	/**
	 * @param  list<sqlDatabase>  $databases
	 */
	private function primeEnvWith( array $databases ): void {
		$env = new environmentConfig();
		$env->sqlDatabases = $databases;
		$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'environmentConfig' );
		$prop->setValue( null, $env );
	}

}
