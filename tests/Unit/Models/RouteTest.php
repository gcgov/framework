<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\route;
use gcgov\framework\models\routeHandler;

#[CoversClass(route::class)]
#[CoversClass(routeHandler::class)]
final class RouteTest extends TestCase {

	public function testRouteDefaultsAreEmpty(): void {
		$route = new route();
		$this->assertSame( '', $route->httpMethod );
		$this->assertSame( '', $route->route );
		$this->assertSame( '', $route->class );
		$this->assertSame( '', $route->method );
		$this->assertFalse( $route->authentication );
		$this->assertSame( [], $route->requiredRoles );
		$this->assertFalse( $route->allowShortLivedUrlTokens );
	}

	public function testRouteFullConstructorPopulatesProperties(): void {
		$route = new route(
			'POST',
			'/widgets',
			'\app\controllers\widget',
			'save',
			true,
			[ 'Widget.Write' ],
			true
		);
		$this->assertSame( 'POST', $route->httpMethod );
		$this->assertSame( '/widgets', $route->route );
		$this->assertSame( '\app\controllers\widget', $route->class );
		$this->assertSame( 'save', $route->method );
		$this->assertTrue( $route->authentication );
		$this->assertSame( [ 'Widget.Write' ], $route->requiredRoles );
		$this->assertTrue( $route->allowShortLivedUrlTokens );
	}

	public function testRouteHttpMethodCanBeArray(): void {
		$route = new route( [ 'GET', 'HEAD' ], '/widgets' );
		$this->assertSame( [ 'GET', 'HEAD' ], $route->httpMethod );
	}

	public function testRouteHandlerRequiresClassAndMethod(): void {
		$handler = new routeHandler( '\app\controllers\widget', 'save' );
		$this->assertSame( '\app\controllers\widget', $handler->class );
		$this->assertSame( 'save', $handler->method );
		$this->assertFalse( $handler->authentication );
		$this->assertSame( [], $handler->requiredRoles );
		$this->assertFalse( $handler->allowShortLivedUrlTokens );
		$this->assertSame( [], $handler->arguments );
	}

	public function testRouteHandlerArgumentsAreMutable(): void {
		$handler = new routeHandler( '\some\controller', 'method' );
		$handler->arguments = [ 'foo', 42 ];
		$this->assertSame( [ 'foo', 42 ], $handler->arguments );
	}

	public function testRouteHandlerFullConstructorAssignsAllFields(): void {
		$handler = new routeHandler(
			'\app\controllers\widget',
			'getOne',
			true,
			[ 'Widget.Read' ],
			true
		);
		$this->assertTrue( $handler->authentication );
		$this->assertSame( [ 'Widget.Read' ], $handler->requiredRoles );
		$this->assertTrue( $handler->allowShortLivedUrlTokens );
	}

}
