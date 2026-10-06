<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use gcgov\framework\services\auth\providers\oauth\services\multifactor;
use gcgov\framework\services\auth\providers\oauth\models\requireMfaResponse;

#[CoversClass(multifactor::class)]
final class MultifactorTest extends TestCase {

	public function testRequireMfaResponseDelegatesToConstructor(): void {
		$user = $this->buildUser( true, false );
		$response = multifactor::requireMfaResponse( null, $user );

		$this->assertInstanceOf( requireMfaResponse::class, $response );
		$this->assertTrue( $response->mfaRequired );
		$this->assertFalse( $response->mfaConfigured );
	}

	public function testRequireMfaResponseIncludesAccessTokenString(): void {
		$user = $this->buildUser( true, true );
		$token = $this->buildToken();
		$response = multifactor::requireMfaResponse( $token, $user );

		$this->assertSame( $token->toString(), $response->access_token );
		$this->assertTrue( $response->mfaRequired );
		$this->assertTrue( $response->mfaConfigured );
	}

	public function testRequireMfaResponseStaticAndReturnsModelType(): void {
		$method = new \ReflectionMethod( multifactor::class, 'requireMfaResponse' );
		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
		$this->assertSame( requireMfaResponse::class, (string) $method->getReturnType() );
	}

	public function testConfigureMfaResponseIsStaticWithMongoIdArgument(): void {
		$method = new \ReflectionMethod( multifactor::class, 'configureMfaResponse' );
		$this->assertTrue( $method->isStatic() );

		$params = $method->getParameters();
		$this->assertSame( 'userId', $params[0]->getName() );
		$this->assertSame( \MongoDB\BSON\ObjectId::class, (string) $params[0]->getType() );
		$this->assertTrue( $params[1]->allowsNull() );
	}

	public function testVerifyMfaSecretIsStaticAndReturnsUserInterface(): void {
		$method = new \ReflectionMethod( multifactor::class, 'verifyMfaSecret' );
		$this->assertTrue( $method->isStatic() );
		$this->assertSame( \gcgov\framework\interfaces\auth\user::class, (string) $method->getReturnType() );
	}

	public function testIsMfaCodeCorrectIsStaticAndReturnsBool(): void {
		$method = new \ReflectionMethod( multifactor::class, 'isMfaCodeCorrect' );
		$this->assertTrue( $method->isStatic() );
		$this->assertSame( 'bool', (string) $method->getReturnType() );
	}

	private function buildToken(): Plain {
		$exp = ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) );
		return new Plain(
			new DataSet( [ 'typ' => 'JWT', 'alg' => 'none' ], 'h' ),
			new DataSet( [ 'exp' => $exp ], 'p' ),
			new Signature( '', '' )
		);
	}

	private function buildUser( bool $mfaRequired, bool $mfaConfigured ): \gcgov\framework\interfaces\auth\user {
		return new class( $mfaRequired, $mfaConfigured ) implements \gcgov\framework\interfaces\auth\user {
			public bool $mfaRequired;
			public bool $mfaConfigured;
			public function __construct( bool $mfaRequired, bool $mfaConfigured ) {
				$this->mfaRequired = $mfaRequired;
				$this->mfaConfigured = $mfaConfigured;
			}
			public function getId(): string|int|\MongoDB\BSON\ObjectId { return new \MongoDB\BSON\ObjectId(); }
			public function getName(): string { return ''; }
			public function getUsername(): string { return ''; }
			public function getPassword(): string { return ''; }
			public function getOauthId(): string { return ''; }
			public function getOauthProvider(): string { return ''; }
			public function getEmail(): string { return ''; }
			public function getRoles(): array { return []; }
			public function getActive(): bool { return true; }
			public function getMfaRequired(): bool { return $this->mfaRequired; }
			public function getMfaConfigured(): bool { return $this->mfaConfigured; }
			public static function getFromOauth( string $email, string $externalId, string $externalProvider, ?string $firstName = '', ?string $lastName = '', bool $addIfNotExisting = false, array $rolesForNewUser=[] ): self { throw new \BadMethodCallException(); }
			public static function verifyUsernamePassword( string $username, string $password ): self { throw new \BadMethodCallException(); }
			public static function getOneByExternalId( string $externalId ): self { throw new \BadMethodCallException(); }
			public static function getOneByEmail( string $email ): self { throw new \BadMethodCallException(); }
			public static function getOne( \MongoDB\BSON\ObjectId|string|int $_id ): self { throw new \BadMethodCallException(); }
			public static function save( object &$object ): mixed { return null; }
		};
	}

}
