<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use MongoDB\BSON\ObjectId;
use gcgov\framework\services\auth\providers\oauth\models\verifyMfaSecretRequest;

#[CoversClass(verifyMfaSecretRequest::class)]
final class VerifyMfaSecretRequestTest extends TestCase {

	public function testDefaultValuesAreEmptyAndNull(): void {
		$request = new verifyMfaSecretRequest();
		$this->assertSame( '', $request->code );
		$this->assertNull( $request->userMultifactorId );
	}

	public function testConstructorAssignsCodeAndObjectId(): void {
		$id = new ObjectId();
		$request = new verifyMfaSecretRequest( 'abc', $id );
		$this->assertSame( 'abc', $request->code );
		$this->assertSame( (string) $id, (string) $request->userMultifactorId );
	}

	public function testConstructorWithCodeOnlyLeavesIdNull(): void {
		$request = new verifyMfaSecretRequest( '12345' );
		$this->assertNull( $request->userMultifactorId );
	}

	public function testExtendsJsonDeserialize(): void {
		$this->assertTrue(
			is_subclass_of(
				verifyMfaSecretRequest::class,
				\andrewsauder\jsonDeserialize\jsonDeserialize::class
			)
		);
	}

}
