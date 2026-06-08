<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\auth\user;

#[CoversClass(user::class)]
final class AuthUserStructuralTest extends TestCase {

	public function testCollectionConstants(): void {
		$this->assertSame( 'user', user::_COLLECTION );
		$this->assertSame( 'user', user::_HUMAN );
		$this->assertSame( 'users', user::_HUMAN_PLURAL );
	}

	public function testImplementsAuthUserInterface(): void {
		$implements = class_implements( user::class ) ?: [];
		$this->assertContains( \gcgov\framework\interfaces\auth\user::class, $implements );
	}

	public function testExtendsFrameworkMongoModel(): void {
		$this->assertTrue(
			is_subclass_of( user::class, \gcgov\framework\services\mongodb\model::class )
		);
	}

	public function testUsesUserTrait(): void {
		$reflection = new \ReflectionClass( user::class );
		$traits = $reflection->getTraitNames();
		$this->assertContains( \gcgov\framework\traits\userTrait::class, $traits );
	}

	public function testGetOneRejectsIntegerIds(): void {
		$this->expectException( \gcgov\framework\exceptions\modelException::class );
		$this->expectExceptionMessage( 'Integer ids are not supported' );
		user::getOne( 42 );
	}

	public function testGetOneRequiresOptionalSession(): void {
		$method = new \ReflectionMethod( user::class, 'getOne' );
		$params = $method->getParameters();
		$this->assertCount( 2, $params );
		$this->assertSame( '_id', $params[0]->getName() );
		$this->assertSame( 'mongoDbSession', $params[1]->getName() );
		$this->assertTrue( $params[1]->allowsNull() );
	}

	public function testGetFromOauthRequiresEmailAndExternalFields(): void {
		$method = new \ReflectionMethod( user::class, 'getFromOauth' );
		$params = $method->getParameters();
		$this->assertSame( 'email', $params[0]->getName() );
		$this->assertSame( 'externalId', $params[1]->getName() );
		$this->assertSame( 'externalProvider', $params[2]->getName() );
	}

	public function testVerifyUsernamePasswordReturnsInterfaceImplementation(): void {
		$method = new \ReflectionMethod( user::class, 'verifyUsernamePassword' );
		$this->assertSame(
			\gcgov\framework\interfaces\auth\user::class,
			(string) $method->getReturnType()
		);
	}

}
