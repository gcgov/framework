<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\internalCounter;

#[CoversClass(internalCounter::class)]
final class InternalCounterTest extends TestCase {

	public function testCollectionConstantsAreDefined(): void {
		$this->assertSame( 'internalCounter', internalCounter::_COLLECTION );
		$this->assertSame( 'internal counter', internalCounter::_HUMAN );
		$this->assertSame( 'internal counters', internalCounter::_HUMAN_PLURAL );
	}

	public function testIsFinalAndExtendsModel(): void {
		$reflection = new \ReflectionClass( internalCounter::class );
		$this->assertTrue( $reflection->isFinal() );
		$this->assertTrue(
			is_subclass_of( internalCounter::class, \gcgov\framework\services\mongodb\model::class )
		);
	}

	public function testDefaultValues(): void {
		$counter = new internalCounter();
		$this->assertSame( '', $counter->_id );
		$this->assertSame( 0, $counter->currentCount );
	}

	public function testGetAndIncrementIsStaticAndRequiresSession(): void {
		$method = new \ReflectionMethod( internalCounter::class, 'getAndIncrement' );
		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
		$this->assertSame( 'self', (string) $method->getReturnType() );

		$params = $method->getParameters();
		$this->assertSame( '_id', $params[0]->getName() );
		$this->assertSame( 'string', (string) $params[0]->getType() );
	}

}
