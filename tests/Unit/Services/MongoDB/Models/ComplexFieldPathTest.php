<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use MongoDB\BSON\ObjectId;
use gcgov\framework\services\mongodb\models\complexFieldPath;

#[CoversClass(complexFieldPath::class)]
final class ComplexFieldPathTest extends TestCase {

	public function testSinglePositionalConvertedToArrayFilter(): void {
		$id = new ObjectId();
		$path = new complexFieldPath( 'inspections.$', true, $id );

		$this->assertSame( 'inspections.$[arrayFilter0]', $path->getComplexPath() );
		$filters = $path->getArrayFilters();
		$this->assertCount( 1, $filters );
		$this->assertArrayHasKey( 'arrayFilter0._id', $filters[0] );
		$this->assertSame( (string) $id, (string) $filters[0][ 'arrayFilter0._id' ] );
	}

	public function testNestedPositionalsBuildMultipleArrayFilters(): void {
		$id = new ObjectId();
		$path = new complexFieldPath( 'inspections.$.scheduleRequests.$.comments.$', true, $id );

		$complex = $path->getComplexPath();
		$this->assertStringStartsWith( 'inspections.$[arrayFilter', $complex );
		$this->assertStringContainsString( 'scheduleRequests.$[arrayFilter', $complex );
		$this->assertStringContainsString( 'comments.$[arrayFilter0]', $complex );

		$filters = $path->getArrayFilters();
		$this->assertGreaterThanOrEqual( 1, count( $filters ) );
	}

	public function testWithoutArrayFilterStripsTerminalDollar(): void {
		// useArrayFilter=false on the first positional $ unsets that segment
		// rather than replacing it with $[]. The result is the dotted path
		// with the $ removed.
		$path = new complexFieldPath( 'inspections.$.name', false );
		$this->assertSame( 'inspections.name', $path->getComplexPath() );
		$this->assertSame( [], $path->getArrayFilters() );
	}

	public function testPlainPathHasNoArrayFilters(): void {
		$path = new complexFieldPath( 'inspections.name', true );
		$this->assertSame( 'inspections.name', $path->getComplexPath() );
		$this->assertSame( [], $path->getArrayFilters() );
	}

}
