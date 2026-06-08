<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use MongoDB\BSON\ObjectId;
use gcgov\framework\services\mongodb\tools\helpers;
use gcgov\framework\exceptions\modelException;

#[CoversClass(helpers::class)]
final class HelpersTest extends TestCase {

	public function testStringToObjectIdAcceptsValidHexString(): void {
		$id = helpers::stringToObjectId( '507f1f77bcf86cd799439011' );
		$this->assertInstanceOf( ObjectId::class, $id );
		$this->assertSame( '507f1f77bcf86cd799439011', (string) $id );
	}

	public function testStringToObjectIdPassesThroughExistingObjectId(): void {
		$existing = new ObjectId();
		$result = helpers::stringToObjectId( $existing );
		$this->assertSame( $existing, $result );
	}

	public function testStringToObjectIdThrowsModelExceptionForInvalidString(): void {
		try {
			helpers::stringToObjectId( 'not-valid' );
			$this->fail( 'Expected modelException' );
		}
		catch ( modelException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( 'Invalid _id', $e->getMessage() );
		}
	}

	public function testStringToObjectIdUsesCustomMessage(): void {
		try {
			helpers::stringToObjectId( 'bad', 'Custom error' );
			$this->fail( 'Expected modelException' );
		}
		catch ( modelException $e ) {
			$this->assertSame( 'Custom error', $e->getMessage() );
		}
	}

	public function testJsonToObjectParsesJsonString(): void {
		$result = helpers::jsonToObject( '{"name":"Alice","age":30}' );
		$this->assertInstanceOf( \stdClass::class, $result );
		$this->assertSame( 'Alice', $result->name );
	}

	public function testJsonToObjectPassesThroughObject(): void {
		$obj = new \stdClass();
		$obj->name = 'Bob';
		$this->assertSame( $obj, helpers::jsonToObject( $obj ) );
	}

	public function testJsonToObjectThrowsForInvalidJson(): void {
		try {
			helpers::jsonToObject( '{not-valid-json' );
			$this->fail( 'Expected modelException' );
		}
		catch ( modelException $e ) {
			$this->assertSame( 400, $e->getCode() );
		}
	}

	public function testJsonToObjectUsesCustomMessageAndCode(): void {
		try {
			helpers::jsonToObject( 'not json', 'Bad JSON', 422 );
			$this->fail( 'Expected modelException' );
		}
		catch ( modelException $e ) {
			$this->assertSame( 'Bad JSON', $e->getMessage() );
			$this->assertSame( 422, $e->getCode() );
		}
	}

}
