<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth;

use gcgov\framework\exceptions\routeException;
use gcgov\framework\models\config\services\auth as authConfig;
use gcgov\framework\models\route;
use gcgov\framework\models\routeHandler;
use gcgov\framework\services\auth\router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two auth services became one, chosen by provider. These carry forward what the two
 * separate router tests asserted: the route shapes each provider contributes, and the
 * guard's refusal behaviour — which used to be two near-identical copies.
 */
#[CoversClass( router::class )]
final class RouterTest extends TestCase {

	private static function router( string $provider ): router {
		return new router( authConfig::jsonDeserialize( (object)[ 'provider' => $provider ] ) );
	}


	protected function setUp(): void {
		unset( $_SERVER[ 'HTTP_AUTHORIZATION' ], $_GET[ 'fileAccessToken' ] );
	}


	private static function findRoute( router $router, string $method ): route {
		foreach( $router->getRoutes() as $route ) {
			if( $route->method===$method ) {
				return $route;
			}
		}
		throw new \LogicException( 'No route with method=' . $method );
	}


	public function testRouterImplementsTheFrameworkRouterInterface(): void {
		$this->assertContains( \gcgov\framework\interfaces\router::class, class_implements( router::class ) ?: [] );
	}


	public function testOauthProviderRegistersNineRoutes(): void {
		$routes = self::router( 'oauth' )->getRoutes();
		$this->assertCount( 9, $routes );
		foreach( $routes as $route ) {
			$this->assertInstanceOf( route::class, $route );
		}
	}


	public function testMsFrontProviderRegistersThreeRoutes(): void {
		$this->assertCount( 3, self::router( 'msFront' )->getRoutes() );
	}


	/** jwks and fileToken are the same endpoints whichever provider is selected. */
	public function testSharedRoutesArePresentForBothProviders(): void {
		foreach( [ 'oauth', 'msFront' ] as $provider ) {
			$router = self::router( $provider );

			$jwks = self::findRoute( $router, 'jwks' );
			$this->assertSame( 'GET', $jwks->httpMethod );
			$this->assertSame( '/api/.well-known/jwks.json', $jwks->route );
			$this->assertFalse( $jwks->authentication );

			$fileToken = self::findRoute( $router, 'fileToken' );
			$this->assertSame( '/api/auth/fileToken', $fileToken->route );
			$this->assertTrue( $fileToken->authentication );
		}
	}


	public function testOpenidConfigurationRouteIsPublic(): void {
		$route = self::findRoute( self::router( 'oauth' ), 'openId' );
		$this->assertSame( 'GET', $route->httpMethod );
		$this->assertSame( '/api/.well-known/openid-configuration', $route->route );
		$this->assertFalse( $route->authentication );
	}


	public function testAuthorizeRoutesShareOnePathAcrossTwoMethods(): void {
		$router = self::router( 'oauth' );

		$post = self::findRoute( $router, 'oauthPostAuthorize' );
		$this->assertSame( 'POST', $post->httpMethod );
		$this->assertSame( '/api/auth/authorize', $post->route );
		$this->assertFalse( $post->authentication );

		$get = self::findRoute( $router, 'oauthGetAuthorize' );
		$this->assertSame( 'GET', $get->httpMethod );
		$this->assertSame( '/api/auth/authorize', $get->route );
	}


	public function testHybridAuthRouteHasProviderPlaceholder(): void {
		$this->assertSame( '/api/auth/hybridauth/{provider}', self::findRoute( self::router( 'oauth' ), 'oauthHybridAuth' )->route );
	}


	public function testMfaRoutesArePostAndAuthenticated(): void {
		foreach( [ 'verifyMfaSecret', 'verifyMfaCode' ] as $method ) {
			$route = self::findRoute( self::router( 'oauth' ), $method );
			$this->assertSame( 'POST', $route->httpMethod );
			$this->assertTrue( $route->authentication );
		}
	}


	public function testMicrosoftExchangeRouteIsPublic(): void {
		$route = self::findRoute( self::router( 'msFront' ), 'microsoft' );
		$this->assertSame( 'GET', $route->httpMethod );
		$this->assertSame( '/api/auth/microsoft', $route->route );
		$this->assertFalse( $route->authentication );
	}


	public function testMfaRoutesAreAbsentUnderMsFront(): void {
		$methods = array_map( fn( route $r ): string => $r->method, self::router( 'msFront' )->getRoutes() );
		$this->assertNotContains( 'verifyMfaCode', $methods );
		$this->assertNotContains( 'oauthPostAuthorize', $methods );
	}


	public function testMissingAuthorizationHeaderIs401( ): void {
		foreach( [ 'oauth', 'msFront' ] as $provider ) {
			try {
				self::router( $provider )->authentication( new routeHandler( '\some\controller', 'm' ) );
				$this->fail( 'Expected routeException for ' . $provider );
			}
			catch( routeException $e ) {
				$this->assertSame( 401, $e->getCode() );
				$this->assertSame( 'Missing Authorization', $e->getMessage() );
			}
		}
	}


	public function testShortLivedUrlTokensAllowedButNoTokenSuppliedIs401(): void {
		$handler                           = new routeHandler( '\some\controller', 'm' );
		$handler->allowShortLivedUrlTokens = true;

		try {
			self::router( 'oauth' )->authentication( $handler );
			$this->fail( 'Expected routeException' );
		}
		catch( routeException $e ) {
			$this->assertSame( 401, $e->getCode() );
		}
	}


	public function testMalformedJwtIsRejected(): void {
		$_SERVER[ 'HTTP_AUTHORIZATION' ] = 'not.a.valid.jwt';

		$this->expectException( \Throwable::class );
		self::router( 'oauth' )->authentication( new routeHandler( '\some\controller', 'm' ) );
	}

}
