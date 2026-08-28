<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Health;

use gcgov\framework\models\route;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\services\health\router;
use gcgov\framework\tests\Support\seedsFrameworkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(router::class)]
final class RouterTest extends TestCase {

	use seedsFrameworkConfig;

	public function testRouterImplementsFrameworkRouterInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\router::class,
			class_implements( router::class ) ?: []
		);
	}


	/**
	 * The router interface deliberately declares no lifecycle hooks — only \app\router's
	 * are ever invoked — so a service router must not carry hooks that will never fire.
	 */
	public function testRouterDeclaresNoLifecycleHooks(): void {
		$this->assertFalse( method_exists( router::class, '_before' ) );
		$this->assertFalse( method_exists( router::class, '_after' ) );
	}


	public function testBothProbesAreRegisteredUnauthenticated(): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = 'api' );

		$routes = ( new router() )->getRoutes();

		$this->assertCount( 2, $routes );
		foreach( $routes as $route ) {
			$this->assertInstanceOf( route::class, $route );
			$this->assertSame( 'GET', $route->httpMethod );
			$this->assertFalse( $route->authentication, 'a probe an orchestrator calls cannot require a token' );
			$this->assertSame( [], $route->requiredRoles );
			$this->assertNotSame( '', $route->description, 'probes appear in gf cli:list and shell completion' );
		}
	}


	public function testLivenessAndReadinessArePathsUnderTheBasePath(): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = 'api' );

		$routes = ( new router() )->getRoutes();

		$this->assertSame( '/api/health', $routes[ 0 ]->route );
		$this->assertSame( 'live', $routes[ 0 ]->method );
		$this->assertSame( '/api/health/ready', $routes[ 1 ]->route );
		$this->assertSame( 'ready', $routes[ 1 ]->method );
	}


	public function testProbePathsAreSingleSlashedAtDomainRoot(): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = '' );

		$routes = ( new router() )->getRoutes();

		$this->assertSame( '/health', $routes[ 0 ]->route );
		$this->assertSame( '/health/ready', $routes[ 1 ]->route );
	}


	public function testAuthenticationAllowsTheProbes(): void {
		$routeHandler = $this->createStub( \gcgov\framework\models\routeHandler::class );
		$this->assertTrue( ( new router() )->authentication( $routeHandler ) );
	}

}
