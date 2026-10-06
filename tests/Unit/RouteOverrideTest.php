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
	 * FastRoute compiles a placeholder's regex, never its name, so user/{id} and
	 * user/{_id} are the SAME route to the dispatcher — and must collide here, or both
	 * register and BadRouteException takes every url down: the exact whole-surface outage
	 * the override mechanism exists to prevent, for a v6 app whose placeholder is merely
	 * spelled differently from the framework's.
	 */
	public function testPlaceholderSpellingDoesNotDefeatTheCollision(): void {
		$framework = $this->routeKeys( new route( 'GET', '/api/user/{_id}', '\gcgov\framework\services\userCrud\controllers\user', 'getOne' ) );
		$app       = $this->routeKeys( new route( 'GET', '/api/user/{userId}', '\app\controllers\user', 'getOne' ) );

		self::assertSame( $framework, $app );
	}


	/** A custom placeholder regex compiles differently, so it is a different shape. */
	public function testACustomPlaceholderRegexIsADifferentShape(): void {
		$plain       = $this->routeKeys( new route( 'GET', '/api/user/{id}', '\app\controllers\user', 'getOne' ) );
		$constrained = $this->routeKeys( new route( 'GET', '/api/user/{id:\d+}', '\app\controllers\user', 'getOne' ) );

		self::assertSame( [], array_intersect( $plain, $constrained ) );
	}


	public function testOptionalSegmentsOccupyOneSlotPerVariant(): void {
		$keys = $this->routeKeys( new route( 'GET', '/api/widget[/{id}]', '\app\controllers\widget', 'get' ) );

		self::assertCount( 2, $keys );
	}


	/**
	 * A static application route inside a variable service route's shape is also fatal:
	 * service routes register first, and FastRoute rejects a static route shadowed by an
	 * earlier variable one. The application's path must win there too.
	 */
	public function testAStaticAppRouteOverridesTheVariableServiceRouteThatWouldShadowIt(): void {
		$service = [ new route( 'GET', '/api/user/{_id}', '\gcgov\framework\services\userCrud\controllers\user', 'getOne' ) ];
		$app     = [ new route( 'GET', '/api/user/me', '\app\controllers\user', 'me' ) ];

		self::assertSame( [], router::serviceRoutesNotOverridden( $service, $app ) );
	}


	public function testAnUnrelatedStaticAppRouteDropsNothing(): void {
		$service = [ new route( 'GET', '/api/user/{_id}', '\gcgov\framework\services\userCrud\controllers\user', 'getOne' ) ];
		$app     = [ new route( 'GET', '/api/widget/me', '\app\controllers\widget', 'me' ) ];

		self::assertSame( $service, router::serviceRoutesNotOverridden( $service, $app ) );
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


	/**
	 * The (method, shape) slots a route occupies, built on the router's public
	 * patternShapes() — the same signatures the override filter compares.
	 *
	 * @return string[]
	 */
	private function routeKeys( route $route ): array {
		$keys = [];
		foreach( (array)$route->httpMethod as $httpMethod ) {
			foreach( router::patternShapes( $route->route ) as $shape ) {
				$keys[] = strtoupper( (string)$httpMethod ) . ' ' . $shape[ 'signature' ];
			}
		}

		return $keys;
	}

}
