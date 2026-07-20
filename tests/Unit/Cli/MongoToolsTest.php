<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\mongoTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(mongoTools::class)]
final class MongoToolsTest extends TestCase {

	public function testRedactUriMasksPassword(): void {
		$this->assertSame( 'mongodb://appUser:***@c-mongo:27017/AppsSchedule?authSource=AppsSchedule', mongoTools::redactUri( 'mongodb://appUser:s3cr3t@c-mongo:27017/AppsSchedule?authSource=AppsSchedule' ) );
		$this->assertSame( 'mongodb+srv://u:***@cluster.example.net/db', mongoTools::redactUri( 'mongodb+srv://u:p%40ss@cluster.example.net/db' ) );
	}

	public function testRedactUriLeavesPasswordlessUriUntouched(): void {
		$this->assertSame( 'mongodb://localhost:27017', mongoTools::redactUri( 'mongodb://localhost:27017' ) );
	}

	public function testUriWithDatabaseAppendsWhenMissing(): void {
		$this->assertSame( 'mongodb://localhost:27017/widgets', mongoTools::uriWithDatabase( 'mongodb://localhost:27017', 'widgets' ) );
		$this->assertSame( 'mongodb://u:p@h:27017/widgets?authSource=admin', mongoTools::uriWithDatabase( 'mongodb://u:p@h:27017?authSource=admin', 'widgets' ) );
		$this->assertSame( 'mongodb://h:27017/widgets', mongoTools::uriWithDatabase( 'mongodb://h:27017/', 'widgets' ) );
	}

	public function testUriWithDatabaseLeavesExistingDatabase(): void {
		$this->assertSame( 'mongodb://h:27017/other?authSource=admin', mongoTools::uriWithDatabase( 'mongodb://h:27017/other?authSource=admin', 'widgets' ) );
	}

	public function testUriWithDatabaseNoopsOnEmptyDatabase(): void {
		$this->assertSame( 'mongodb://h:27017', mongoTools::uriWithDatabase( 'mongodb://h:27017', '' ) );
	}

}
