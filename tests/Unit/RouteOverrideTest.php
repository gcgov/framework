<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\models\route;
use gcgov\framework\router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An application defining a route the framework already registers must win it — and, more
 * to the point, must not lose everything else.
 *
 * FastRoute throws BadRouteException on a duplicate (method, pattern). That exception is
 * neither a routeException nor a configException, so a v6 application upgrading with its own
 * /health did not lose /health: it lost EVERY route, with an empty 500 on every url. The
 * health router's docblock already promised such an application "keeps working".
 */
#[CoversClass(router::class)]
final class RouteOverrideTest extends TestCase {

	public function testRouteKeysCoverEveryMethodARouteIsRegisteredFor(): void {
		$keys = $this->routeKeys( new route( [ 'GET', 'CLI' ], '/cli/report', '\app\controllers\report', 'run' ) );

		self::assertSame( [ 'GET /cli/report', 'CLI /cli/report' ], $keys );
	}


	public function testRouteKeysNormaliseTheMethodCase(): void {
		$keys = $this->routeKeys( new route( 'get', '/widget', '\app\controllers\widget', 'getAll' ) );

		self::assertSame( [ 'GET /widget' ], $keys );
	}


	/**
	 * A collision is per (method, pattern): an application POSTing to a path the framework
	 * only serves with GET is not a collision at all.
	 */
	public function testDifferentMethodsOnTheSamePathDoNotCollide(): void {
		$framework = $this->routeKeys( new route( 'GET', '/api/health', '\gcgov\framework\services\health\controllers\health', 'live' ) );
		$app       = $this->routeKeys( new route( 'POST', '/api/health', '\app\controllers\status', 'record' ) );

		self::assertSame( [], array_intersect( $framework, $app ) );
	}


	public function testSameMethodAndPathCollide(): void {
		$framework = $this->routeKeys( new route( 'GET', '/api/health', '\gcgov\framework\services\health\controllers\health', 'live' ) );
		$app       = $this->routeKeys( new route( 'GET', '/api/health', '\app\controllers\status', 'health' ) );

		self::assertSame( [ 'GET /api/health' ], array_values( array_intersect( $framework, $app ) ) );
	}


	/**
	 * The merged table must never contain a duplicate (method, pattern) — that is exactly
	 * what FastRoute rejects, and rejecting it takes the whole application down.
	 */
	public function testMergedRoutesContainNoDuplicateMethodAndPattern(): void {
		$seen = [];
		foreach( router::getMergedRoutes() as $mergedRoute ) {
			foreach( $this->routeKeys( $mergedRoute ) as $key ) {
				self::assertArrayNotHasKey( $key, $seen, 'duplicate route "' . $key . '" would make FastRoute reject every route' );
				$seen[ $key ] = true;
			}
		}

		self::assertNotSame( [], $seen );
	}


	/** @return string[] */
	private function routeKeys( route $route ): array {
		$method = new \ReflectionMethod( router::class, 'routeKeys' );

		return $method->invoke( null, $route );
	}

}
