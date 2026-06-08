<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\audit;
use gcgov\framework\services\mongodb\updateDeleteResult;
use MongoDB\BSON\ObjectId;

#[CoversClass(audit::class)]
final class AuditTest extends TestCase {

	protected function setUp(): void {
		$_SERVER[ 'REMOTE_ADDR' ] = '203.0.113.1';
	}

	public function testCollectionConstantsAreDefined(): void {
		$this->assertSame( 'audit', audit::_COLLECTION );
		$this->assertSame( 'audit', audit::_HUMAN );
		$this->assertSame( 'audits', audit::_HUMAN_PLURAL );
	}

	public function testClassIsFinal(): void {
		$this->assertTrue( ( new \ReflectionClass( audit::class ) )->isFinal() );
	}

	public function testCreateAssignsCoreFields(): void {
		$id = new ObjectId();
		$record = audit::create( 'widget', $id, 'CREATE' );

		$this->assertSame( 'widget', $record->collection );
		$this->assertSame( 'CREATE', $record->action );
		$this->assertSame( (string) $id, (string) $record->recordId );
		$this->assertSame( '', $record->message );
		$this->assertNull( $record->data );
	}

	public function testCreateConvertsArrayMessageToCsv(): void {
		$record = audit::create(
			'widget',
			new ObjectId(),
			'UPDATE',
			null,
			[ 'first', 'second', 'third' ]
		);
		$this->assertSame( 'first, second, third', $record->message );
	}

	public function testCreateCopiesCountsFromUpdateDeleteResult(): void {
		$record = audit::create(
			'widget',
			new ObjectId(),
			'UPDATE',
			new updateDeleteResult(),
			'msg',
			[ 'k' => 'v' ]
		);
		$this->assertSame( 0, $record->matched );
		$this->assertSame( 0, $record->modified );
		$this->assertSame( 0, $record->upserted );
		$this->assertSame( 0, $record->deleted );
		$this->assertSame( [ 'k' => 'v' ], $record->data );
	}

	public function testConstructorPopulatesIdAndTimestamp(): void {
		$record = audit::create( 'c', new ObjectId(), 'X' );
		$this->assertInstanceOf( ObjectId::class, $record->_id );
		$this->assertInstanceOf( \DateTimeImmutable::class, $record->dateTimeStamp );
		$this->assertSame( '203.0.113.1', $record->ip );
	}

}
