<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Controllers;

use gcgov\framework\exceptions\controllerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\auth\providers\oauth\controllers\auth;

#[CoversClass(auth::class)]
final class AuthControllerTest extends TestCase {

	public function testControllerImplementsFrameworkControllerInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\controller::class,
			class_implements( auth::class ) ?: []
		);
	}

	public function testConstructorRequiresNoArguments(): void {
		$reflection = new \ReflectionClass( auth::class );
		$constructor = $reflection->getConstructor();
		$this->assertNotNull( $constructor );
		$this->assertSame( 0, $constructor->getNumberOfRequiredParameters() );
		$this->assertInstanceOf( auth::class, new auth() );
	}


	public function testOpenidReturnsControllerDataResponseType(): void {
		$reflection = new \ReflectionMethod( auth::class, 'openId' );
		$this->assertSame(
			\gcgov\framework\models\controllerDataResponse::class,
			(string) $reflection->getReturnType()
		);
	}


	public function testOauthPostAuthorizeMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'oauthPostAuthorize' ) );
	}

	public function testOauthGetAuthorizeMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'oauthGetAuthorize' ) );
	}

	public function testOauthHybridAuthMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'oauthHybridAuth' ) );
	}

	public function testVerifyMfaSecretMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'verifyMfaSecret' ) );
	}

	public function testVerifyMfaCodeMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'verifyMfaCode' ) );
	}

	public function testOutMethodExists(): void {
		$this->assertTrue( method_exists( auth::class, 'out' ) );
	}

	public function testLifecycleHooksReturnVoid(): void {
		auth::_before();
		auth::_after();
		$reflection = new \ReflectionClass( auth::class );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_before' )->getReturnType() );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_after' )->getReturnType() );
	}


	public function testAssertActiveRefusesADisabledAccountWith401(): void {
		try {
			auth::assertActive( self::user( active: false ) );
			$this->fail( 'a disabled account must not be signed in' );
		}
		catch( controllerException $e ) {
			$this->assertSame( 401, $e->getCode() );
			$this->assertSame( auth::INACTIVE_MESSAGE, $e->getMessage() );
		}
	}


	public function testAssertActiveAllowsAnActiveAccount(): void {
		auth::assertActive( self::user( active: true ) );
		$this->addToAssertionCount( 1 );
	}


	/**
	 * Every path that mints tokens runs the active check: the grants through
	 * createAccessTokenResponse(), the password grant before its MFA branch (which mints
	 * its own restricted token), the HybridAuth return before it writes an authorization
	 * code, and the Microsoft exchange in its own controller.
	 */
	public function testEveryTokenIssuingPathChecksThatTheAccountIsActive(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../../../../src/services/auth/providers/oauth/controllers/auth.php' );

		$this->assertSame( 2, substr_count( $source, 'self::assertActive( $user );' ), 'password() and createAccessTokenResponse()' );
		$this->assertStringContainsString( "if( !\$user->getActive() ) {\n\t\t\treturn self::redirect(", $source, 'the HybridAuth return redirects a disabled account back with the message' );

		$msFront = (string)file_get_contents( __DIR__ . '/../../../../../../../src/services/auth/providers/msFront/controllers/auth.php' );
		$this->assertStringContainsString( 'auth::assertActive( $user );', $msFront );
	}


	public function testTheMfaCompletionMethodsExist(): void {
		$this->assertSame( [ 'verifyMfaSecret', 'verifyMfaCode' ], auth::MFA_COMPLETION_METHODS );
		foreach( auth::MFA_COMPLETION_METHODS as $method ) {
			$this->assertTrue( method_exists( auth::class, $method ), $method );
		}
	}


	/** The restricted token the password grant issues is marked, so the guard can refuse it elsewhere. */
	public function testPasswordGrantMarksTheRestrictedTokenAsMfaPending(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../../../../src/services/auth/providers/oauth/controllers/auth.php' );

		$this->assertStringContainsString( 'createAccessToken( $authUser, null, [ jwtAuth::CLAIM_MFA_PENDING => true ] )', $source );
	}


	/** Single use, and only within its lifetime — neither was checked before. */
	public function testAuthorizationCodeIsConsumedBeforeTokensAreMintedAndCheckedAgainstItsExpiration(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../../../../src/services/auth/providers/oauth/controllers/auth.php' );

		$consumed = strpos( $source, 'userAuthorizationCode::delete( $userAuthorizationCode->_id )' );
		$expired  = strpos( $source, '$userAuthorizationCode->expiration<new \\DateTimeImmutable()' );
		$minted   = strpos( $source, 'return $this->createAccessTokenResponse( $user );', (int)$consumed );

		$this->assertNotFalse( $consumed, 'the code is deleted on use' );
		$this->assertNotFalse( $expired, 'the expiration is checked' );
		$this->assertNotFalse( $minted );
		$this->assertLessThan( $expired, $consumed, 'deleted before the expiry check, so an expired code is also gone' );
		$this->assertLessThan( $minted, $expired, 'both before tokens are minted' );
	}


	/** The access token names its own refresh token, so sign-out can revoke exactly that one. */
	public function testTheAccessTokenCarriesTheSessionOfTheRefreshTokenMintedWithIt(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../../../../src/services/auth/providers/oauth/controllers/auth.php' );

		$refresh = strpos( $source, '$refreshToken = $jwtService->createRefreshToken( $authUser );' );
		$access  = strpos( $source, '$accessToken  = $jwtService->createAccessToken( $authUser, null, [ jwtAuth::CLAIM_SESSION_ID => $refreshToken->claims()->get( \'sub\' ) ] );' );

		$this->assertNotFalse( $refresh );
		$this->assertNotFalse( $access );
		$this->assertLessThan( $access, $refresh, 'the refresh token is minted first, so its id is known' );
	}


	/** Sign-out revokes this session's refresh token: one row, never all of the user's. */
	public function testSignOutRevokesOnlyThisSessionsRefreshToken(): void {
		$method = new \ReflectionMethod( auth::class, 'out' );
		$source = implode( '', array_slice(
			(array)file( (string)$method->getFileName() ),
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1
		) );

		$this->assertStringContainsString( 'revokeSessionRefreshToken( $authUser->getSessionId(), $authUser->userId )', $source );
		$this->assertStringNotContainsString( 'TODO', $source );
		$this->assertStringNotContainsString( 'deleteMany', $source );
	}


	public function testOpenIdAdvertisesOnlyWhatTheTokenEndpointImplements(): void {
		$data = ( new auth() )->openId()->getData();

		$this->assertSame( [ 'code' ], $data[ 'response_types_supported' ], 'no implicit flow' );
		$this->assertSame( [ 'none' ], $data[ 'token_endpoint_auth_methods_supported' ], 'client_id only, no secret or assertion' );
		$this->assertSame( [ 'authorization_code', 'password', 'refresh_token' ], $data[ 'grant_types_supported' ] );
	}


	private static function user( bool $active ): \gcgov\framework\interfaces\auth\user {
		return new class( $active ) implements \gcgov\framework\interfaces\auth\user {
			public function __construct( private readonly bool $active ) {
			}
			public function getId(): string|int|\MongoDB\BSON\ObjectId { return 'u1'; }
			public function getName(): string { return 'Someone'; }
			public function getUsername(): string { return 'someone'; }
			public function getPassword(): string { return ''; }
			public function getOauthId(): string { return ''; }
			public function getOauthProvider(): string { return ''; }
			public function getEmail(): string { return 'someone@example.test'; }
			public function getRoles(): array { return []; }
			public function getActive(): bool { return $this->active; }
			public function getMfaRequired(): bool { return false; }
			public function getMfaConfigured(): bool { return false; }
			public static function getFromOauth( string $email, string $externalId, string $externalProvider, ?string $firstName = '', ?string $lastName = '', bool $addIfNotExisting = false, array $rolesForNewUser = [] ): self { throw new \BadMethodCallException(); }
			public static function verifyUsernamePassword( string $username, string $password ): self { throw new \BadMethodCallException(); }
			public static function getOneByExternalId( string $externalId ): self { throw new \BadMethodCallException(); }
			public static function getOneByEmail( string $email ): self { throw new \BadMethodCallException(); }
			public static function getOne( \MongoDB\BSON\ObjectId|string|int $_id ): self { throw new \BadMethodCallException(); }
			public static function save( object &$object ): mixed { throw new \BadMethodCallException(); }
		};
	}

}
