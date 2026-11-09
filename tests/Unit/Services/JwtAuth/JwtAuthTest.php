<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\JwtAuth;

use gcgov\framework\exceptions\configException;
use gcgov\framework\exceptions\serviceException;
use gcgov\framework\services\jwtAuth\jwtAuth;
use gcgov\framework\services\request;
use gcgov\framework\tests\Support\generatesJwtKeys;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha512;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of jwtAuth against real signing keys — what JwtAuthStructuralTest cannot
 * reach. The `kid` header is the attacker's: it arrives on a token nothing has verified
 * yet, and used to go straight into init(string) and from there into a file path.
 */
#[CoversClass(jwtAuth::class)]
final class JwtAuthTest extends TestCase {

	use generatesJwtKeys;


	protected function setUp(): void {
		$this->seedConfigWithJwtKeys();
	}


	public function testAccessTokenRoundTrips(): void {
		$jwtService = new jwtAuth();
		$token      = $jwtService->createAccessToken( $this->authUser() );

		$validated = $jwtService->validateAccessToken( 'Bearer ' . $token->toString() );

		$this->assertSame( 'abc123', $validated->claims()->get( 'data' )[ 'userId' ] );
		$this->assertSame( [ 'Widget.Read' ], $validated->claims()->get( 'scope' ) );
		$this->assertContains( $validated->headers()->get( 'kid' ), self::$jwtKeyGuids );
		$this->assertFalse( $validated->claims()->has( jwtAuth::CLAIM_MFA_PENDING ), 'an ordinary token carries no MFA-pending claim' );
	}


	public function testTokenWithoutKidIsRefusedAsInvalidKidNotTypeError(): void {
		$token = $this->signWithoutValidKid( self::$jwtKeyGuids[ 0 ], kid: null );

		$this->expectException( serviceException::class );
		$this->expectExceptionMessage( 'GUID/kid of token is not valid' );
		( new jwtAuth() )->validateAccessToken( $token );
	}


	public function testTokenWithNonStringKidIsRefusedAsInvalidKid(): void {
		$token = $this->signWithoutValidKid( self::$jwtKeyGuids[ 0 ], kid: 12345 );

		$this->expectException( serviceException::class );
		( new jwtAuth() )->validateAccessToken( $token );
	}


	/**
	 * A keypair that is on disk but not in guids.json is not one this application signs
	 * with. Before, any private-/public- pair under the key directory was accepted.
	 */
	public function testTokenNamingAKeyNotListedInGuidsJsonIsRefused(): void {
		$strayGuid = self::writeKeypair( 'ffffffff-0000-0000-0000-00000000dead' );
		$token     = $this->signWithoutValidKid( $strayGuid, kid: $strayGuid );

		try {
			( new jwtAuth() )->validateAccessToken( $token );
			$this->fail( 'a key guids.json does not list must not verify a token' );
		}
		catch( serviceException $e ) {
			$this->assertStringContainsString( 'kid', $e->getMessage() );
		}
		finally {
			unlink( self::$jwtKeyDir . 'private-' . $strayGuid . '.pem' );
			unlink( self::$jwtKeyDir . 'public-' . $strayGuid . '.pem' );
		}
	}


	public function testRefreshTokenValidationAppliesTheSameKidRule(): void {
		$token = $this->signWithoutValidKid( self::$jwtKeyGuids[ 0 ], kid: null );

		$this->expectException( serviceException::class );
		( new jwtAuth() )->validateRefreshToken( $token );
	}


	public function testMalformedGuidsJsonIsAConfigurationError(): void {
		$dir = sys_get_temp_dir() . '/gcgov-jwt-bad-guids-' . uniqid() . '/';
		mkdir( $dir, 0700, true );
		file_put_contents( $dir . 'guids.json', '{"not": "a list"}' );
		$this->seedConfigWithJwtKeys( static function( $c ) use ( $dir ): void {
			$c->jwtAuth->keyPath = $dir;
		} );

		try {
			$this->expectException( configException::class );
			$this->expectExceptionMessage( 'guids.json' );
			new jwtAuth();
		}
		finally {
			unlink( $dir . 'guids.json' );
			rmdir( $dir );
		}
	}


	public function testAdditionalClaimsAreWrittenToTheAccessToken(): void {
		$token = ( new jwtAuth() )->createAccessToken( $this->authUser(), null, [ jwtAuth::CLAIM_MFA_PENDING => true ] );

		$this->assertTrue( $token->claims()->get( jwtAuth::CLAIM_MFA_PENDING ) );
	}


	public function testSessionClaimIsWrittenToTheAccessToken(): void {
		$token = ( new jwtAuth() )->createAccessToken( $this->authUser(), null, [ jwtAuth::CLAIM_SESSION_ID => '507f1f77bcf86cd799439011' ] );

		$this->assertSame( 'sid', jwtAuth::CLAIM_SESSION_ID, 'the OpenID Connect session ID claim' );
		$this->assertSame( '507f1f77bcf86cd799439011', $token->claims()->get( jwtAuth::CLAIM_SESSION_ID ) );
	}


	/**
	 * A session or user id that is not an ObjectId names no refresh token. It returns false
	 * before any database call, rather than throwing from `new ObjectId()`.
	 */
	public function testRevokingAMalformedSessionRevokesNothing(): void {
		$jwtService = new jwtAuth();

		$this->assertFalse( $jwtService->revokeSessionRefreshToken( '', '507f1f77bcf86cd799439011' ) );
		$this->assertFalse( $jwtService->revokeSessionRefreshToken( 'not-an-object-id', '507f1f77bcf86cd799439011' ) );
		$this->assertFalse( $jwtService->revokeSessionRefreshToken( '507f1f77bcf86cd799439011', 'abc123' ) );
	}


	public function testRegisteredClaimsCannotBeOverriddenThroughAdditionalClaims(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new jwtAuth() )->createAccessToken( $this->authUser(), null, [ 'scope' => [ 'Admin' ] ] );
	}


	private function authUser(): \gcgov\framework\models\authUser {
		$authUser         = request::getAuthUser();
		$authUser->userId = 'abc123';
		$authUser->email  = 'someone@example.test';
		$authUser->roles  = [ 'Widget.Read' ];

		return $authUser;
	}


	/**
	 * A token signed with one of the real keys, but with a kid header jwtAuth would never
	 * write — the shape an attacker who holds a stray key, or no key, would send.
	 */
	private function signWithoutValidKid( string $signingGuid, mixed $kid ): string {
		$configuration = Configuration::forAsymmetricSigner(
			new Sha512(),
			InMemory::file( self::$jwtKeyDir . 'private-' . $signingGuid . '.pem' ),
			InMemory::file( self::$jwtKeyDir . 'public-' . $signingGuid . '.pem' )
		);

		$now     = new \DateTimeImmutable();
		$builder = $configuration->builder()
			->issuedBy( 'http://test.local' )
			->permittedFor( '/api' )
			->issuedAt( $now )
			->canOnlyBeUsedAfter( $now )
			->expiresAt( $now->add( new \DateInterval( 'PT1H' ) ) )
			->withClaim( 'scope', [] )
			->withClaim( 'data', [ 'userId' => 'abc123' ] );
		if( $kid!==null ) {
			$builder = $builder->withHeader( 'kid', $kid );
		}

		return $builder->getToken( $configuration->signer(), $configuration->signingKey() )->toString();
	}

}
