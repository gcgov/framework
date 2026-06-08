<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\auditUser;

#[CoversClass(auditUser::class)]
final class AuditUserTest extends TestCase {

	protected function setUp(): void {
		$this->resetSingleton();
	}

	protected function tearDown(): void {
		$this->resetSingleton();
	}

	public function testGetInstanceReturnsSingleton(): void {
		$a = auditUser::getInstance();
		$b = auditUser::getInstance();
		$this->assertSame( $a, $b );
	}

	public function testDefaultsAreNullIdAndEmptyName(): void {
		$user = auditUser::getInstance();
		$this->assertNull( $user->userId );
		$this->assertSame( '', $user->name );
	}

	public function testFieldsArePublicallyMutable(): void {
		$user = auditUser::getInstance();
		$user->name = 'Alice';
		$user->userId = new \MongoDB\BSON\ObjectId();
		$this->assertSame( 'Alice', $user->name );
		$this->assertInstanceOf( \MongoDB\BSON\ObjectId::class, $user->userId );
	}

	public function testConstructorIsPrivate(): void {
		$this->assertTrue(
			( new \ReflectionMethod( auditUser::class, '__construct' ) )->isPrivate()
		);
	}

	public function testClassIsFinal(): void {
		$this->assertTrue( ( new \ReflectionClass( auditUser::class ) )->isFinal() );
	}

	private function resetSingleton(): void {
		$prop = new \ReflectionProperty( auditUser::class, 'instance' );
		if ( $prop->isInitialized() ) {
			$prop->setValue( null, ( new \ReflectionClass( auditUser::class ) )->newInstanceWithoutConstructor() );
		}
	}

}
