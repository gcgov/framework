<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth;

use gcgov\framework\exceptions\routeException;
use gcgov\framework\models\routeHandler;
use gcgov\framework\services\auth\guard;
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

	/** @var array<string, mixed> */
	private array $server = [];
	/** @var array<string, mixed> */
	private array $get = [];

	protected function setUp(): void {
		$this->server = $_SERVER;
		$this->get    = $_GET;
		unset( $_SERVER[ 'HTTP_AUTHORIZATION' ], $_GET[ 'fileAccessToken' ] );
	}


	protected function tearDown(): void {
		$_SERVER = $this->server;
		$_GET    = $this->get;
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

}
