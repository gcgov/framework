<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use gcgov\framework\services\auth\providers\oauth\models\requireMfaResponse;
use gcgov\framework\services\auth\providers\oauth\models\stdAuthResponse;

#[CoversClass(requireMfaResponse::class)]
final class RequireMfaResponseTest extends TestCase {

	public function testExtendsStdAuthResponse(): void {
		$this->assertTrue( is_subclass_of( requireMfaResponse::class, stdAuthResponse::class ) );
	}

	public function testDefaultsWithNoArguments(): void {
		$response = new requireMfaResponse();
		$this->assertTrue( $response->mfaRequired );
		$this->assertTrue( $response->mfaConfigured );
		$this->assertSame( '', $response->access_token );
	}

	public function testAccessTokenWithoutUserPreservesDefaultFlags(): void {
		$token = $this->buildToken();
		$response = new requireMfaResponse( $token );

		$this->assertSame( $token->toString(), $response->access_token );
		$this->assertTrue( $response->mfaRequired );
		$this->assertTrue( $response->mfaConfigured );
	}

	public function testUserDictatesFlagValues(): void {
		$user = $this->buildUser( false, false );
		$response = new requireMfaResponse( null, $user );

		$this->assertFalse( $response->mfaRequired );
		$this->assertFalse( $response->mfaConfigured );
	}

	public function testUserAndAccessTokenCombineCorrectly(): void {
		$token = $this->buildToken();
		$user = $this->buildUser( true, false );
		$response = new requireMfaResponse( $token, $user );

		$this->assertSame( $token->toString(), $response->access_token );
		$this->assertTrue( $response->mfaRequired );
		$this->assertFalse( $response->mfaConfigured );
	}

	private function buildToken(): Plain {
		$exp = ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) );
		return new Plain(
			new DataSet( [ 'typ' => 'JWT', 'alg' => 'none' ], 'header' ),
			new DataSet( [ 'exp' => $exp ], 'payload' ),
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
