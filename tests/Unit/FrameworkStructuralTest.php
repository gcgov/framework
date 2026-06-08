<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\framework;
use gcgov\framework\router;
use gcgov\framework\renderer;

/**
 * Structural tests for the top-level framework boot classes. They wire up the
 * full request/response lifecycle in production and aren't safe to instantiate
 * inside a test runner (they execute the route, write headers, call die()),
 * so we cover their public contract instead.
 */
#[CoversClass(framework::class)]
#[CoversClass(router::class)]
#[CoversClass(renderer::class)]
final class FrameworkStructuralTest extends TestCase {

	public function testFrameworkClassExists(): void {
		$this->assertTrue( class_exists( framework::class ) );
	}

	public function testFrameworkConstructorRequiresNoArguments(): void {
		$ctor = ( new \ReflectionClass( framework::class ) )->getConstructor();
		$this->assertNotNull( $ctor );
		$this->assertSame( 0, $ctor->getNumberOfRequiredParameters() );
	}

	public function testFrameworkRunAppReturnsString(): void {
		$method = new \ReflectionMethod( framework::class, 'runApp' );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}

	public function testRouterConstructorTakesServiceNamespaces(): void {
		$ctor = ( new \ReflectionClass( router::class ) )->getConstructor();
		$this->assertNotNull( $ctor );
		$params = $ctor->getParameters();
		$this->assertCount( 1, $params );
		$this->assertSame( 'serviceNamespaces', $params[0]->getName() );
		$this->assertSame( 'array', (string) $params[0]->getType() );
	}

	public function testRouterRouteReturnsRouteHandler(): void {
		$method = new \ReflectionMethod( router::class, 'route' );
		$this->assertSame(
			\gcgov\framework\models\routeHandler::class,
			(string) $method->getReturnType()
		);
	}

	public function testRendererConstructorRequiresNoArguments(): void {
		$ctor = ( new \ReflectionClass( renderer::class ) )->getConstructor();
		$this->assertNotNull( $ctor );
		$this->assertSame( 0, $ctor->getNumberOfRequiredParameters() );
	}

	public function testRendererRenderAcceptsRouteHandlerOrRouteException(): void {
		$method = new \ReflectionMethod( renderer::class, 'render' );
		$type = $method->getParameters()[0]->getType();
		$this->assertNotNull( $type );
		$signature = (string) $type;
		$this->assertStringContainsString( 'routeHandler', $signature );
		$this->assertStringContainsString( 'routeException', $signature );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}

}
