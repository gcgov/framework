<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\typeMapType;

#[CoversClass(typeMapType::class)]
final class TypeMapTypeTest extends TestCase {

	public function testEnumHasSerializeCase(): void {
		$this->assertSame( 'serialize', typeMapType::serialize->value );
	}

	public function testEnumHasUnserializeCase(): void {
		$this->assertSame( 'unserialize', typeMapType::unserialize->value );
	}

	public function testEnumCasesAreLimitedToTwo(): void {
		$this->assertCount( 2, typeMapType::cases() );
	}

	public function testFromStringReturnsCorrectCase(): void {
		$this->assertSame( typeMapType::serialize, typeMapType::from( 'serialize' ) );
		$this->assertSame( typeMapType::unserialize, typeMapType::from( 'unserialize' ) );
	}

	public function testTryFromUnknownReturnsNull(): void {
		$this->assertNull( typeMapType::tryFrom( 'something-else' ) );
	}

}
