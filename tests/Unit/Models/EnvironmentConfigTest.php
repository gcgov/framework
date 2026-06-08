<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\environmentConfig;
use gcgov\framework\models\config\environment\sqlDatabase;

#[CoversClass(environmentConfig::class)]
final class EnvironmentConfigTest extends TestCase {

	public function testConstructorInitializesNestedConfigs(): void {
		$config = new environmentConfig();
		$this->assertInstanceOf(
			\gcgov\framework\models\config\environment\microsoft::class,
			$config->microsoft
		);
		$this->assertInstanceOf(
			\gcgov\framework\models\config\environment\jwtAuth::class,
			$config->jwtAuth
		);
		$this->assertInstanceOf(
			\gcgov\framework\models\config\environment\payjunction::class,
			$config->payjunction
		);
		$this->assertInstanceOf(
			\gcgov\framework\models\config\environment\logging::class,
			$config->logging
		);
	}

	public function testGetRootUrlTrimsTrailingSlashesAndSpaces(): void {
		$config = new environmentConfig();
		$config->rootUrl = 'https://example.com/ ';
		$this->assertSame( 'https://example.com', $config->getRootUrl() );
	}

	public function testGetBaseUrlCombinesRootAndBasePath(): void {
		$config = new environmentConfig();
		$config->rootUrl = 'https://example.com/';
		$config->basePath = '/api/v1/';
		$this->assertSame( 'https://example.com/api/v1', $config->getBaseUrl() );
	}

	public function testGetBasePathReturnsLeadingSlashTrimmedValue(): void {
		$config = new environmentConfig();
		$config->basePath = 'api/v1 ';
		$this->assertSame( '/api/v1', $config->getBasePath() );
	}

	public function testIsLocalReturnsTrueWhenTypeIsLocal(): void {
		$config = new environmentConfig();
		$config->type = 'local';
		$this->assertTrue( $config->isLocal() );
	}

	public function testIsLocalReturnsFalseForOtherEnvironments(): void {
		$config = new environmentConfig();
		$config->type = 'production';
		$this->assertFalse( $config->isLocal() );
	}

	public function testGetDefaultSqlDatabaseReturnsTheOneMarkedDefault(): void {
		$config = new environmentConfig();
		$db1 = new sqlDatabase();
		$db1->name = 'db1';
		$db1->default = false;
		$db2 = new sqlDatabase();
		$db2->name = 'db2';
		$db2->default = true;
		$config->sqlDatabases = [ $db1, $db2 ];

		$this->assertSame( $db2, $config->getDefaultSqlDatabase() );
	}

	public function testGetDefaultSqlDatabaseReturnsNullWhenNoneDefault(): void {
		$config = new environmentConfig();
		$db = new sqlDatabase();
		$db->default = false;
		$config->sqlDatabases = [ $db ];
		$this->assertNull( $config->getDefaultSqlDatabase() );
	}

	public function testGetSqlDatabaseByNameMatches(): void {
		$config = new environmentConfig();
		$db = new sqlDatabase();
		$db->name = 'primary';
		$config->sqlDatabases = [ $db ];

		$this->assertSame( $db, $config->getSqlDatabaseByName( 'primary' ) );
		$this->assertNull( $config->getSqlDatabaseByName( 'missing' ) );
	}

	public function testAppDictionaryIsArrayByDefault(): void {
		$config = new environmentConfig();
		$this->assertSame( [], $config->appDictionary );
	}

}
