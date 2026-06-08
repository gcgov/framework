<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\getResult;

#[CoversClass(getResult::class)]
final class GetResultTest extends TestCase {

	public function testDefaultsLimitToTenPageOneEmptyData(): void {
		$r = new getResult();
		$this->assertSame( 10, $r->getLimit() );
		$this->assertSame( 1, $r->getPage() );
		$this->assertSame( [], $r->getData() );
		$this->assertSame( 0, $r->getCount() );
		$this->assertSame( -1, $r->getTotalDocumentCount() );
	}

	public function testConstructorAcceptsStringNumericValues(): void {
		$r = new getResult( '25', '3', [ 'a', 'b' ] );
		$this->assertSame( 25, $r->getLimit() );
		$this->assertSame( 3, $r->getPage() );
		$this->assertSame( [ 'a', 'b' ], $r->getData() );
	}

	public function testConstructorWithNonNumericFallsBackToDefaults(): void {
		$r = new getResult( 'abc', 'xyz' );
		$this->assertSame( 10, $r->getLimit() );
		$this->assertSame( 1, $r->getPage() );
	}

	public function testSettersUpdateFields(): void {
		$r = new getResult();
		$r->setLimit( 50 );
		$r->setPage( 4 );
		$r->setData( [ 1, 2, 3 ] );
		$r->setTotalDocumentCount( 200 );

		$this->assertSame( 50, $r->getLimit() );
		$this->assertSame( 4, $r->getPage() );
		$this->assertSame( [ 1, 2, 3 ], $r->getData() );
		$this->assertSame( 200, $r->getTotalDocumentCount() );
	}

	public function testCountReflectsDataLength(): void {
		$r = new getResult( 10, 1, [ 'a', 'b', 'c' ] );
		$this->assertSame( 3, $r->getCount() );
	}

	public function testSkipIsZeroOnPageOne(): void {
		$r = new getResult( 10, 1 );
		$this->assertSame( 0, $r->getSkip() );
	}

	public function testSkipIsLimitTimesPagesMinusOne(): void {
		$r = new getResult( 25, 3 );
		$this->assertSame( 50, $r->getSkip() );
	}

	public function testTotalPageCountRoundsUp(): void {
		$r = new getResult( 10 );
		$r->setTotalDocumentCount( 23 );
		$this->assertSame( 3, $r->getTotalPageCount() );
	}

	public function testTotalPageCountIsZeroWhenNoDocuments(): void {
		$r = new getResult( 10 );
		$r->setTotalDocumentCount( 0 );
		$this->assertSame( 0, $r->getTotalPageCount() );
	}

	public function testImplementsDbGetResult(): void {
		$this->assertInstanceOf(
			\gcgov\framework\interfaces\dbGetResult::class,
			new getResult()
		);
	}

}
