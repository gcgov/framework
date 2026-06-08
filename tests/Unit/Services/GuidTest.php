<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use gcgov\framework\services\guid;

final class GuidTest extends TestCase {

	public function testCreateReturnsGuidV4Format(): void {
		$value = guid::create();
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}

	public function testCreateReturnsUniqueValues(): void {
		$values = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$values[] = guid::create();
		}
		$this->assertCount( 50, array_unique( $values ) );
	}

	public function testCreateTrimsBracesByDefault(): void {
		$this->assertDoesNotMatchRegularExpression( '/[{}]/', guid::create() );
	}

}
