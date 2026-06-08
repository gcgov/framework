<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\JwtAuth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\jwtAuth\models\userAuthorizationCode;
use MongoDB\BSON\ObjectId;

#[CoversClass(userAuthorizationCode::class)]
final class UserAuthorizationCodeTest extends TestCase {

	public function testCollectionConstantsAreDefined(): void {
		$this->assertSame( 'userAuthorizationCode', userAuthorizationCode::_COLLECTION );
		$this->assertSame( 'user authorization code', userAuthorizationCode::_HUMAN );
		$this->assertSame( 'user authorization codes', userAuthorizationCode::_HUMAN_PLURAL );
	}

	public function testConstructorAssignsUserIdAndDuration(): void {
		$userId = new ObjectId();
		$duration = new \DateInterval( 'PT1H' );
		$code = new userAuthorizationCode( $userId, $duration );

		$this->assertInstanceOf( ObjectId::class, $code->_id );
		$this->assertSame( $userId, $code->userId );
		$this->assertInstanceOf( \DateTimeImmutable::class, $code->creation );
		$this->assertInstanceOf( \DateTimeImmutable::class, $code->expiration );

		$diff = $code->expiration->getTimestamp() - $code->creation->getTimestamp();
		$this->assertEqualsWithDelta( 3600, $diff, 5 );
	}

	public function testClassIsFinal(): void {
		$this->assertTrue( ( new \ReflectionClass( userAuthorizationCode::class ) )->isFinal() );
	}

}
