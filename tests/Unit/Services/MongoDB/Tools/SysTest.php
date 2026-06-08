<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\sys;

#[CoversClass(sys::class)]
final class SysTest extends TestCase {

	public function testMethodExistsReturnsTrueForRealMethod(): void {
		$this->assertTrue( sys::methodExists( sys::class, 'methodExists' ) );
	}

	public function testMethodExistsReturnsFalseForMissingMethod(): void {
		$this->assertFalse( sys::methodExists( sys::class, 'somethingThatDoesNotExist' ) );
	}

	public function testPropertyExistsReturnsTrueForRealProperty(): void {
		$this->assertTrue( sys::propertyExists( SysTestSample::class, 'value' ) );
	}

	public function testPropertyExistsReturnsFalseForMissingProperty(): void {
		$this->assertFalse( sys::propertyExists( SysTestSample::class, 'missing' ) );
	}

	public function testRepeatedLookupsUseCachedResult(): void {
		// First call populates cache, second call reads from cache. Both should
		// return the same answer.
		$first = sys::methodExists( SysTestSample::class, 'hello' );
		$second = sys::methodExists( SysTestSample::class, 'hello' );
		$this->assertTrue( $first );
		$this->assertSame( $first, $second );
	}

}

class SysTestSample {
	public string $value = '';
	public function hello(): string { return 'hi'; }
}
