<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\migrateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * migrateCommand::plan() is a pure function of the two v6 documents, which is the whole
 * reason the conversion lives in code: it can be run against every application's real
 * configuration without touching a filesystem.
 */
#[CoversClass(migrateCommand::class)]
final class MigrateCommandTest extends TestCase {

	/** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
	private function v6Fixture(): array {
		$appJson = [
			'app'      => [ 'title' => 'Permits API', 'guid' => 'f1f2f3' ],
			'email'    => [ 'fromAddress' => 'noreply@example.gov', 'fromName' => 'Permits' ],
			'settings' => [ 'useSession' => false ],
		];

		$environmentJson = [
			'type'           => 'prod',
			'serverName'     => 'permits.example.gov',
			'rootUrl'        => 'https://permits.example.gov',
			'basePath'       => '/api/',
			'baseUrl'        => 'https://permits.example.gov/api/',
			'cookieUrl'      => 'https://permits.example.gov',
			'phpPath'        => 'E:\\php',
			'mongoDatabases' => [
				[ 'default' => true, 'database' => 'permits', 'uri' => 'mongodb+srv://user:hunter2@cluster/' ],
			],
			'microsoft'      => [ 'clientId' => 'abc', 'clientSecret' => 'super-secret', 'tenant' => 'contoso' ],
			'jwtAuth'        => [ 'tokenIssuedBy' => 'https://permits.example.gov', 'redirectAfterLoginUrl' => 'https://permits.example.gov/app/in' ],
		];

		return [ $appJson, $environmentJson ];
	}


	public function testAppJsonSectionsMergeIntoTheUnifiedConfig(): void {
		[ $appJson, $environmentJson ] = $this->v6Fixture();

		$plan = migrateCommand::plan( $appJson, $environmentJson );

		self::assertSame( 'Permits API', $plan[ 'config' ][ 'app' ][ 'title' ] );
		self::assertSame( 'f1f2f3', $plan[ 'config' ][ 'app' ][ 'guid' ] );
		self::assertSame( 'noreply@example.gov', $plan[ 'config' ][ 'email' ][ 'fromAddress' ] );
		self::assertFalse( $plan[ 'config' ][ 'settings' ][ 'useSession' ] );
	}


	public function testRemovedKeysAreDroppedAndReported(): void {
		[ $appJson, $environmentJson ] = $this->v6Fixture();

		$plan = migrateCommand::plan( $appJson, $environmentJson );

		foreach( [ 'serverName', 'cookieUrl', 'phpPath', 'baseUrl' ] as $removed ) {
			self::assertArrayNotHasKey( $removed, $plan[ 'config' ] );
			self::assertNotEmpty(
				array_filter( $plan[ 'warnings' ], fn( string $warning ): bool => str_contains( $warning, '"' . $removed . '"' ) ),
				$removed . ' should be reported, not silently dropped'
			);
		}
	}


	/** The point of the whole exercise: credentials leave the committed file. */
	public function testCredentialsBecomeSecretReferencesAndTheValuesMoveToTheEnv(): void {
		[ $appJson, $environmentJson ] = $this->v6Fixture();

		$plan = migrateCommand::plan( $appJson, $environmentJson );

		self::assertSame( '%env(secret:MONGO_URI)%', $plan[ 'config' ][ 'mongoDatabases' ][ 0 ][ 'uri' ] );
		self::assertSame( '%env(MONGO_DATABASE)%', $plan[ 'config' ][ 'mongoDatabases' ][ 0 ][ 'database' ] );
		self::assertSame( '%env(secret:MICROSOFT_CLIENT_SECRET)%', $plan[ 'config' ][ 'microsoft' ][ 'clientSecret' ] );

		self::assertSame( 'mongodb+srv://user:hunter2@cluster/', $plan[ 'env' ][ 'MONGO_URI' ] );
		self::assertSame( 'permits', $plan[ 'env' ][ 'MONGO_DATABASE' ] );
		self::assertSame( 'super-secret', $plan[ 'env' ][ 'MICROSOFT_CLIENT_SECRET' ] );

		self::assertTrue( $plan[ 'secrets' ][ 'MONGO_URI' ] );
		self::assertTrue( $plan[ 'secrets' ][ 'MICROSOFT_CLIENT_SECRET' ] );
		self::assertFalse( $plan[ 'secrets' ][ 'MONGO_DATABASE' ] );
	}


	public function testNonSecretIdentityValuesBecomePlainReferences(): void {
		[ $appJson, $environmentJson ] = $this->v6Fixture();

		$plan = migrateCommand::plan( $appJson, $environmentJson );

		self::assertSame( '%env(APP_TYPE)%', $plan[ 'config' ][ 'type' ] );
		self::assertSame( '%env(APP_ROOT_URL)%', $plan[ 'config' ][ 'rootUrl' ] );
		self::assertSame( '%env(APP_BASE_PATH)%', $plan[ 'config' ][ 'basePath' ] );
		self::assertSame( 'prod', $plan[ 'env' ][ 'APP_TYPE' ] );
		self::assertSame( '/api/', $plan[ 'env' ][ 'APP_BASE_PATH' ] );
	}


	public function testEmptyValuesAreLeftAloneRatherThanBecomingRequiredReferences(): void {
		$plan = migrateCommand::plan( [], [ 'type' => 'local', 'microsoft' => [ 'clientSecret' => '' ] ] );

		self::assertSame( '', $plan[ 'config' ][ 'microsoft' ][ 'clientSecret' ] );
		self::assertArrayNotHasKey( 'MICROSOFT_CLIENT_SECRET', $plan[ 'env' ] );
	}


	public function testSecondAndSubsequentDatabasesGetDistinctVariables(): void {
		$plan = migrateCommand::plan( [], [
			'mongoDatabases' => [
				[ 'database' => 'primary', 'uri' => 'mongodb://one' ],
				[ 'database' => 'archive', 'uri' => 'mongodb://two' ],
			],
		] );

		self::assertSame( '%env(secret:MONGO_URI)%', $plan[ 'config' ][ 'mongoDatabases' ][ 0 ][ 'uri' ] );
		self::assertSame( '%env(secret:MONGO_URI_2)%', $plan[ 'config' ][ 'mongoDatabases' ][ 1 ][ 'uri' ] );
		self::assertSame( 'mongodb://one', $plan[ 'env' ][ 'MONGO_URI' ] );
		self::assertSame( 'mongodb://two', $plan[ 'env' ][ 'MONGO_URI_2' ] );
	}


	/**
	 * An IIS application that upgrades must not silently stop writing its log files —
	 * changing destination is a decision for whoever containerises it.
	 */
	public function testLoggingDestinationIsPinnedToFileToPreserveV6Behaviour(): void {
		[ $appJson, $environmentJson ] = $this->v6Fixture();

		$plan = migrateCommand::plan( $appJson, $environmentJson );

		self::assertSame( 'file', $plan[ 'config' ][ 'logging' ][ 'destination' ] );
		self::assertNotEmpty( array_filter( $plan[ 'warnings' ], fn( string $w ): bool => str_contains( $w, 'logging.destination' ) ) );
	}


	public function testSqlDatabasesAreReportedRatherThanGuessedAt(): void {
		$plan = migrateCommand::plan( [], [ 'sqlDatabases' => [ [ 'name' => 'legacy', 'dsn' => 'pgsql:host=db' ] ] ] );

		self::assertSame( [ [ 'name' => 'legacy', 'dsn' => 'pgsql:host=db' ] ], $plan[ 'config' ][ 'sqlDatabases' ] );
		self::assertNotEmpty( array_filter( $plan[ 'warnings' ], fn( string $w ): bool => str_contains( $w, 'sqlDatabases' ) ) );
	}


	public function testMissingGuidIsReportedBecauseOauthUsesItAsTheClientId(): void {
		$plan = migrateCommand::plan( [ 'app' => [ 'title' => 'No Guid' ] ], [ 'type' => 'local' ] );

		self::assertNotEmpty( array_filter( $plan[ 'warnings' ], fn( string $w ): bool => str_contains( $w, 'app.guid' ) ) );
	}


	public function testReferenceRendering(): void {
		self::assertSame( '%env(FOO)%', migrateCommand::reference( 'FOO', false ) );
		self::assertSame( '%env(secret:FOO)%', migrateCommand::reference( 'FOO', true ) );
	}


	public function testDeadFilesPresentFindsBothFixedPathsAndEnvironmentVariants(): void {
		$root = sys_get_temp_dir() . '/gcgov-migrate-test-' . uniqid();
		mkdir( $root . '/app/config', 0777, true );
		mkdir( $root . '/www', 0777, true );
		touch( $root . '/app/config/app.json' );
		touch( $root . '/app/config/environment.json' );
		touch( $root . '/app/config/environment-prod.json' );
		touch( $root . '/www/web-prod.config' );

		$present = migrateCommand::deadFilesPresent( $root );

		self::assertContains( 'app/config/app.json', $present );
		self::assertContains( 'app/config/environment.json', $present );
		self::assertContains( 'app/config/environment-prod.json', $present );
		self::assertContains( 'www/web-prod.config', $present );
		self::assertNotContains( 'update-production.ps1', $present, 'only files that actually exist' );

		foreach( [ $root . '/app/config/app.json', $root . '/app/config/environment.json', $root . '/app/config/environment-prod.json', $root . '/www/web-prod.config' ] as $file ) {
			unlink( $file );
		}
		rmdir( $root . '/app/config' );
		rmdir( $root . '/app' );
		rmdir( $root . '/www' );
		rmdir( $root );
	}

}
