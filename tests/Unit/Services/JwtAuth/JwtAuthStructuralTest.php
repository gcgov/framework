<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\JwtAuth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\jwtAuth\jwtAuth;

#[CoversClass(jwtAuth::class)]
final class JwtAuthStructuralTest extends TestCase {

	public function testCreateAccessTokenReturnsPlainToken(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'createAccessToken' );
		$this->assertSame(
			\Lcobucci\JWT\Token\Plain::class,
			(string) $method->getReturnType()
		);
		$params = $method->getParameters();
		$this->assertSame( 'authUser', $params[0]->getName() );
		$this->assertTrue( $params[1]->allowsNull() );
	}

	public function testCreateRefreshTokenReturnsPlainToken(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'createRefreshToken' );
		$this->assertSame(
			\Lcobucci\JWT\Token\Plain::class,
			(string) $method->getReturnType()
		);
	}

	public function testValidateAccessTokenReturnsTokenInterface(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'validateAccessToken' );
		$this->assertSame( \Lcobucci\JWT\Token::class, (string) $method->getReturnType() );

		$params = $method->getParameters();
		$this->assertCount( 1, $params );
		$this->assertSame( 'string', (string) $params[0]->getType() );
	}

	public function testValidateRefreshTokenReturnsMongoObjectId(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'validateRefreshToken' );
		$this->assertSame(
			\MongoDB\BSON\ObjectId::class,
			(string) $method->getReturnType()
		);
	}

	public function testDeleteRefreshTokenAcceptsString(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'deleteRefreshToken' );
		$params = $method->getParameters();
		$this->assertSame( 'unparsedToken', $params[0]->getName() );
		$this->assertSame( 'string', (string) $params[0]->getType() );
	}

	public function testGetJwksKeysIsPublic(): void {
		$method = new \ReflectionMethod( jwtAuth::class, 'getJwksKeys' );
		$this->assertTrue( $method->isPublic() );
	}

	public function testConstructorAcceptsOptionalGuid(): void {
		$ctor = ( new \ReflectionClass( jwtAuth::class ) )->getConstructor();
		$params = $ctor?->getParameters();
		$this->assertNotNull( $params );
		$this->assertCount( 1, $params );
		$this->assertTrue( $params[0]->allowsNull() );
	}

}
