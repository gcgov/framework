<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\exceptions\configException;
use gcgov\framework\models\route;
use gcgov\framework\router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A route that declares authentication:true and has nothing to enforce it is worse than
 * an unauthenticated route: it looks protected in the route table and in review, and is
 * open to anyone. The framework refuses to serve that arrangement.
 */
#[CoversClass( router::class )]
final class RouterAuthenticationGuaranteeTest extends TestCase {

	/** @return route[] */
	private static function routes( bool $includeAuthenticated ): array {
		$routes = [
			new route( 'GET', '/health', '\gcgov\framework\services\health\controllers\health', 'live' ),
			new route( 'GET', '/widget', '\app\controllers\widget', 'getAll' ),
		];
		if( $includeAuthenticated ) {
			$routes[] = new route( 'POST', '/widget/{_id}', '\app\controllers\widget', 'save', true, [ 'Widget.Write' ] );
			$routes[] = new route( [ 'GET', 'POST' ], '/secret', '\app\controllers\secret', 'run', true );
		}

		return $routes;
	}


	public function testAuthenticatedRoutesWithNoAuthServiceAndNoAppGuardRefuseToBoot(): void {
		$this->expectException( configException::class );
		$this->expectExceptionMessage( '2 route(s) require authentication but no authentication service is enabled' );

		router::assertAuthenticationIsProvided( self::routes( true ), false, false );
	}


	public function testTheMessageNamesTheOffendingRoutes(): void {
		try {
			router::assertAuthenticationIsProvided( self::routes( true ), false, false );
			$this->fail( 'expected a configException' );
		}
		catch( configException $e ) {
			$this->assertStringContainsString( 'POST /widget/{_id}', $e->getMessage() );
			$this->assertStringContainsString( 'GET|POST /secret', $e->getMessage() );
			// and tells the reader both ways out
			$this->assertStringContainsString( '"provider": "oauth"', $e->getMessage() );
			$this->assertStringContainsString( 'providesAuthentication()', $e->getMessage() );
		}
	}


	public function testAnEnabledAuthServiceSatisfiesTheCheck(): void {
		router::assertAuthenticationIsProvided( self::routes( true ), true, false );
		$this->expectNotToPerformAssertions();
	}


	public function testAnApplicationThatGuardsItsOwnRoutesSatisfiesTheCheck(): void {
		router::assertAuthenticationIsProvided( self::routes( true ), false, true );
		$this->expectNotToPerformAssertions();
	}


	/** No authenticated routes means nothing to fail closed about. */
	public function testNoAuthenticatedRoutesNeedsNoAuthService(): void {
		router::assertAuthenticationIsProvided( self::routes( false ), false, false );
		$this->expectNotToPerformAssertions();
	}


	public function testEmptyRouteTableIsFine(): void {
		router::assertAuthenticationIsProvided( [], false, false );
		$this->expectNotToPerformAssertions();
	}


	/**
	 * requiredRoles on an unauthenticated route is contradictory — the route returns before
	 * the guard chain, so the roles can never be checked. It is warned, not refused: the
	 * declaration was already inert, so failing an application's boot over it would break
	 * something that works rather than protect anything.
	 */
	public function testRolesOnAnUnauthenticatedRouteWarnRatherThanRefuse(): void {
		$this->expectNotToPerformAssertions();

		router::assertAuthenticationIsProvided(
			[ new route( 'GET', '/widget', '\app\controllers\widget', 'getAll', false, [ 'Widget.Read' ] ) ],
			false,
			false
		);
	}

}
