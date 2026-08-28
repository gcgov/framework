<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Documentation;

use gcgov\framework\tests\Support\seedsFrameworkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\documentation\router;
use gcgov\framework\models\route;

#[CoversClass(router::class)]
final class RouterTest extends TestCase {

	use seedsFrameworkConfig;

	/**
	 * Seed the base path explicitly rather than relying on whatever configuration a
	 * previously-run test happened to leave behind. Multi-segment on purpose: it catches
	 * a router that assumes the base path is one path element.
	 */
	protected function setUp(): void {
		$this->seedConfig( static fn( \gcgov\framework\models\unifiedConfig $c ) => $c->basePath = 'api/v1' );
	}




	public function testRouterImplementsFrameworkRouterInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\router::class,
			class_implements( router::class ) ?: []
		);
	}

	public function testGetRoutesReturnsSingleDocumentationYamlRoute(): void {
		$routes = ( new router() )->getRoutes();
		$this->assertCount( 1, $routes );
		$this->assertInstanceOf( route::class, $routes[0] );
	}

	public function testRouteIsGetMethodAtConfiguredBasePath(): void {
		$routes = ( new router() )->getRoutes();
		/** @var route $route */
		$route = $routes[0];

		$this->assertSame( 'GET', $route->httpMethod );
		$this->assertSame( '/api/v1/documentation.yaml', $route->route );
	}

	public function testRouteTargetsDocumentationControllerYamlMethod(): void {
		$routes = ( new router() )->getRoutes();
		/** @var route $route */
		$route = $routes[0];

		$this->assertSame( '\gcgov\framework\services\documentation\controllers\documentation', $route->class );
		$this->assertSame( 'yaml', $route->method );
	}

	public function testRouteIsUnauthenticated(): void {
		$routes = ( new router() )->getRoutes();
		/** @var route $route */
		$route = $routes[0];

		$this->assertFalse( $route->authentication );
	}

	public function testAuthenticationAlwaysReturnsTrue(): void {
		$routeHandler = $this->createStub( \gcgov\framework\models\routeHandler::class );
		$this->assertTrue( ( new router() )->authentication( $routeHandler ) );
	}


}
