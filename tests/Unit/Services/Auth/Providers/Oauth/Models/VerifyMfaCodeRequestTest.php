<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\auth\providers\oauth\models\verifyMfaCodeRequest;

#[CoversClass(verifyMfaCodeRequest::class)]
final class VerifyMfaCodeRequestTest extends TestCase {

	public function testDefaultCodeIsEmpty(): void {
		$request = new verifyMfaCodeRequest();
		$this->assertSame( '', $request->code );
	}

	public function testConstructorAssignsCode(): void {
		$request = new verifyMfaCodeRequest( '123456' );
		$this->assertSame( '123456', $request->code );
	}

	public function testCodeIsPublicallyMutable(): void {
		$request = new verifyMfaCodeRequest();
		$request->code = '987654';
		$this->assertSame( '987654', $request->code );
	}

	public function testExtendsJsonDeserialize(): void {
		$this->assertTrue(
			is_subclass_of(
				verifyMfaCodeRequest::class,
				\andrewsauder\jsonDeserialize\jsonDeserialize::class
			)
		);
	}

}
