<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth;

use gcgov\framework\exceptions\routeException;
use gcgov\framework\models\routeHandler;
use gcgov\framework\services\auth\guard;
use gcgov\framework\services\auth\providers\oauth\controllers\auth as oauthController;
use gcgov\framework\services\jwtAuth\jwtAuth;
use gcgov\framework\services\request;
use gcgov\framework\tests\Support\generatesJwtKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The global guard decides 401 vs 403 vs allow for every authenticated route in every
 * application built on the framework, and shipped with no tests at all.
 *
 * Everything past token validation needs real signing keys, so what is covered here is the
 * part that runs before them: where a token is read from, and the per-route opt-in that
 * governs whether a URL-borne token is accepted.
 *
 * The guard deliberately does NOT check requiredRoles. That moved to
 * router::assertRequiredRoles() so it applies however the caller was authenticated — see
 * tests/Unit/RequiredRolesTest.php — and this class asserts the guard has not quietly kept
 * a second copy, which is the duplication that let the two enforcement paths disagree.
 */
#[CoversClass(guard::class)]
final class GuardTest extends TestCase {

	use generatesJwtKeys;

	/** @var array<string, mixed> */
	private array $server = [];
	/** @var array<string, mixed> */
	private array $get = [];

	protected function setUp(): void {
		$this->server = $_SERVER;
		$this->get    = $_GET;
		unset( $_SERVER[ 'HTTP_AUTHORIZATION' ], $_GET[ 'fileAccessToken' ] );
		$this->seedConfigWithJwtKeys();
		request::getAuthUser()->setFromJwtToken( [], [] );
	}


	protected function tearDown(): void {
		$_SERVER = $this->server;
		$_GET    = $this->get;
		request::getAuthUser()->setFromJwtToken( [], [] );
		request::getAuthUser()->setSessionId( '' );
		$this->restoreConfig();
	}


	public function testMissingAuthorizationHeaderIs401(): void {
		$this->expectException( routeException::class );
		$this->expectExceptionCode( 401 );
		$this->expectExceptionMessage( 'Missing Authorization' );

		guard::authenticate( $this->routeHandler() );
	}


	/**
	 * A token in a URL ends up in access logs, Referer headers and browser history, so a
	 * route has to opt in before one is accepted. Without the opt-in the query parameter is
	 * ignored entirely — not merely rejected later.
	 */
	public function testFileAccessTokenIsIgnoredOnRoutesThatDoNotOptIn(): void {
		$_GET[ 'fileAccessToken' ] = 'a.b.c';

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 401 );
		$this->expectExceptionMessage( 'Missing Authorization' );

		guard::authenticate( $this->routeHandler( allowShortLivedUrlTokens: false ) );
	}


	/**
	 * With the opt-in the token is read, so the guard gets past readToken() and fails
	 * somewhere later instead — never with 'Missing Authorization'.
	 */
	public function testFileAccessTokenIsReadOnRoutesThatOptIn(): void {
		$_GET[ 'fileAccessToken' ] = 'not-a-real-token';

		try {
			guard::authenticate( $this->routeHandler( allowShortLivedUrlTokens: true ) );
			$this->fail( 'an unparseable token must not authenticate' );
		}
		catch( \Throwable $e ) {
			$this->assertStringNotContainsString( 'Missing Authorization', $e->getMessage() );
		}
	}


	public function testAuthorizationHeaderIsPreferredOverTheQueryParameter(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer not-a-real-token';
		$_GET[ 'fileAccessToken' ]       = 'also-not-real';

		try {
			guard::authenticate( $this->routeHandler( allowShortLivedUrlTokens: true ) );
			$this->fail( 'an unparseable token must not authenticate' );
		}
		catch( \Throwable $e ) {
			$this->assertStringNotContainsString( 'Missing Authorization', $e->getMessage() );
		}
	}


	private function routeHandler( bool $allowShortLivedUrlTokens = false ): routeHandler {
		return new routeHandler( '\app\controllers\widget', 'getOne', true, [ 'Widget.Read' ], $allowShortLivedUrlTokens );
	}


	/**
	 * Authorization is not this class's job. A second copy of the role loop here would
	 * reintroduce exactly the split that made requiredRoles enforceable on one path and not
	 * the other.
	 */
	public function testGuardDoesNotCheckRequiredRolesItself(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../src/services/auth/guard.php' );

		self::assertStringNotContainsString( 'requiredRoles as $requiredRole', $source, 'the role loop belongs to router::assertRequiredRoles()' );
		self::assertStringNotContainsString( 'required to access this content', $source, 'the 403 belongs to router::assertRequiredRoles()' );
	}


	/** Establishing the user is this class's job, and the router's check depends on it. */
	public function testGuardPopulatesTheRequestScopedAuthUser(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../../../src/services/auth/guard.php' );

		self::assertStringContainsString( 'request::getAuthUser()', $source );
		self::assertStringContainsString( 'setFromJwtToken', $source );
	}


	public function testAValidTokenAuthenticatesAndEstablishesTheUser(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [ 'Widget.Read' ] )->toString();

		$this->assertTrue( guard::authenticate( $this->routeHandler() ) );
		$this->assertSame( 'abc123', request::getAuthUser()->userId );
		$this->assertSame( [ 'Widget.Read' ], request::getAuthUser()->roles );
	}


	/**
	 * The token the password grant issues while MFA is still owed. Emptying its roles
	 * restricted nothing on a route that declares none — an application's own routes,
	 * /auth/out and /auth/fileToken all took it — so it now carries a claim the guard
	 * honours everywhere but the two routes that complete MFA.
	 */
	public function testMfaPendingTokenIsRefusedOnOrdinaryRoutes(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [], mfaPending: true )->toString();

		try {
			guard::authenticate( new routeHandler( '\\app\\controllers\\widget', 'getAll', true, [], false ) );
			$this->fail( 'a token still owing its second factor must not authenticate an ordinary route' );
		}
		catch( routeException $e ) {
			$this->assertSame( 401, $e->getCode() );
			$this->assertStringContainsString( 'Multi-factor', $e->getMessage() );
		}
		$this->assertSame( '', request::getAuthUser()->userId, 'no user is established for a refused token' );
	}


	public function testMfaPendingTokenIsRefusedOnTheFileTokenRoute(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [], mfaPending: true )->toString();

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 401 );
		guard::authenticate( new routeHandler( '\\gcgov\\framework\\services\\auth\\controllers\\auth', 'fileToken', true, [], false ) );
	}


	public function testMfaPendingTokenIsAcceptedOnTheMfaCompletionRoutes(): void {
		foreach( oauthController::MFA_COMPLETION_METHODS as $method ) {
			$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [], mfaPending: true )->toString();

			$this->assertTrue( guard::authenticate( new routeHandler( '\\' . oauthController::class, $method, true, [], false ) ), $method );
			$this->assertSame( 'abc123', request::getAuthUser()->userId );
		}
	}


	/** Sign-out revokes the refresh token this claim names, so the guard must carry it through. */
	public function testGuardRecordsTheSessionClaimOnTheAuthUser(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [], sessionId: '507f1f77bcf86cd799439011' )->toString();

		$this->assertTrue( guard::authenticate( $this->routeHandler() ) );
		$this->assertSame( '507f1f77bcf86cd799439011', request::getAuthUser()->getSessionId() );
	}


	public function testATokenWithoutASessionClaimLeavesNoSession(): void {
		request::getAuthUser()->setSessionId( '507f1f77bcf86cd799439011' );
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [] )->toString();

		$this->assertTrue( guard::authenticate( $this->routeHandler() ) );
		$this->assertSame( '', request::getAuthUser()->getSessionId(), 'nothing is carried over from an earlier request' );
	}


	public function testANonStringSessionClaimIsIgnored(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'Bearer ' . $this->mint( [], sessionId: [ 'not', 'a', 'string' ] )->toString();

		$this->assertTrue( guard::authenticate( $this->routeHandler() ) );
		$this->assertSame( '', request::getAuthUser()->getSessionId() );
	}


	public function testCompletesMfaRecognisesOnlyTheMfaMethodsOfTheOauthController(): void {
		$this->assertTrue( guard::completesMfa( new routeHandler( '\\' . oauthController::class, 'verifyMfaCode', true, [], false ) ) );
		$this->assertTrue( guard::completesMfa( new routeHandler( oauthController::class, 'verifyMfaSecret', true, [], false ) ), 'with or without the leading backslash' );
		$this->assertFalse( guard::completesMfa( new routeHandler( '\\' . oauthController::class, 'out', true, [], false ) ) );
		$this->assertFalse( guard::completesMfa( new routeHandler( '\\app\\controllers\\auth', 'verifyMfaCode', true, [], false ) ), 'an application controller with the same method name is not the MFA flow' );
	}


	/** @param string[] $roles */
	private function mint( array $roles, bool $mfaPending = false, mixed $sessionId = null ): \Lcobucci\JWT\Token\Plain {
		$authUser         = request::getAuthUser();
		$authUser->userId = 'abc123';
		$authUser->roles  = $roles;

		$claims = $mfaPending ? [ jwtAuth::CLAIM_MFA_PENDING => true ] : [];
		if( $sessionId!==null ) {
			$claims[ jwtAuth::CLAIM_SESSION_ID ] = $sessionId;
		}

		$token = ( new jwtAuth() )->createAccessToken( $authUser, null, $claims );

		// The guard must establish the user from the token, not find it already there.
		$authUser->setFromJwtToken( [], [] );

		return $token;
	}

}
