<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\auth\userMultifactor;
use MongoDB\BSON\ObjectId;

#[CoversClass(userMultifactor::class)]
final class UserMultifactorTest extends TestCase {

	public function testConstructorAssignsUserIdAndIdAndCreatedAt(): void {
		$userId = new ObjectId();
		$mf = new userMultifactor( $userId );

		$this->assertInstanceOf( ObjectId::class, $mf->_id );
		$this->assertSame( $userId, $mf->userId );
		$this->assertInstanceOf( \DateTimeImmutable::class, $mf->createdAt );
		$this->assertSame( '', $mf->secret );
		$this->assertFalse( $mf->verified );
		$this->assertNull( $mf->timeslice );
		$this->assertNull( $mf->verifiedAt );
	}

	public function testCollectionConstants(): void {
		$this->assertSame( 'userMultifactor', userMultifactor::_COLLECTION );
		$this->assertSame( 'user multifactor', userMultifactor::_HUMAN );
		$this->assertSame( 'user multifactors', userMultifactor::_HUMAN_PLURAL );
	}

	public function testExtendsFrameworkMongoModel(): void {
		$this->assertTrue(
			is_subclass_of( userMultifactor::class, \gcgov\framework\services\mongodb\model::class )
		);
	}

	public function testSecretAndVerifiedAreMutable(): void {
		$mf = new userMultifactor( new ObjectId() );
		$mf->secret = 'ABC';
		$mf->verified = true;
		$mf->timeslice = 12345;
		$mf->verifiedAt = new \DateTimeImmutable();

		$this->assertSame( 'ABC', $mf->secret );
		$this->assertTrue( $mf->verified );
		$this->assertSame( 12345, $mf->timeslice );
		$this->assertInstanceOf( \DateTimeImmutable::class, $mf->verifiedAt );
	}

}
