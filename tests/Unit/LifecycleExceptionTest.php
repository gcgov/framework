<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\exceptions\configException;
use gcgov\framework\exceptions\routeException;
use gcgov\framework\framework;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * runApp() used to catch only routeException, while routing throws three other things —
 * and every one of them escaped as a bare PHP fatal:
 *
 *   configException    the fail-closed checks (an authenticated route with no auth service,
 *                      a missing config.json, an unresolved %env() reference). It extends
 *                      \LogicException, which is unrelated to routeException.
 *   BadRouteException  an application defining a route the framework already registers.
 *   \TypeError         an \app\router that does not implement interfaces\appRouter.
 *
 * The consequence was that the checks written to refuse loudly refused silently: no
 * framework error body, and \app\router::_after(), the renderer and \app\app::_after() never
 * ran. This asserts the exception relationships that made that possible, so the catch in
 * runApp() cannot be narrowed back to routeException by accident.
 */
#[CoversClass(framework::class)]
final class LifecycleExceptionTest extends TestCase {

	public function testConfigExceptionIsNotARouteException(): void {
		self::assertNotInstanceOf(
			routeException::class,
			new configException( 'unresolved %env(MONGO_URI)%', 500 ),
			'if these were related the catch below would be unnecessary — they are not'
		);
	}


	public function testFastRouteDuplicateExceptionIsNotARouteException(): void {
		self::assertFalse(
			is_a( \FastRoute\BadRouteException::class, routeException::class, true ),
			'a duplicate route must not be able to escape runApp()'
		);
	}


	/**
	 * The guarantee: every class that routing can throw is caught by runApp()'s handler.
	 * \Throwable is the only catch that covers \TypeError as well as the two exception
	 * hierarchies.
	 */
	public function testRunAppCatchesEveryThrowableFromRouting(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../src/framework.php' );

		$routingBlock = $this->routingTryBlock( $source );

		self::assertStringContainsString( 'catch( routeException $e )', $routingBlock );
		self::assertStringContainsString( 'catch( \Throwable $e )', $routingBlock, 'configException, BadRouteException and TypeError all reach here' );
	}


	/** The lifecycle hooks after routing must still run once a config failure is caught. */
	public function testLifecycleContinuesAfterTheRoutingCatch(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../src/framework.php' );

		$afterCatch = substr( $source, strpos( $source, 'catch( \Throwable $e )' ) ?: 0 );

		self::assertStringContainsString( '\app\router::_after();', $afterCatch );
		self::assertStringContainsString( '\app\renderer::_before();', $afterCatch );
		self::assertStringContainsString( '\app\renderer::_after();', $afterCatch );
		self::assertStringContainsString( '\app\app::_after();', $afterCatch );
	}


	/**
	 * A config failure message names route patterns, the config file path and unresolved
	 * environment variables, so it is logged rather than returned to the caller.
	 */
	public function testCaughtConfigFailureIsLoggedAndGenericised(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../src/framework.php' );

		$routingBlock = $this->routingTryBlock( $source );

		self::assertStringContainsString( 'log::critical', $routingBlock );
		self::assertStringNotContainsString( "routeException( $e->getMessage()", $routingBlock );
		self::assertMatchesRegularExpression( '/new routeException\(\s*\'[^\']+\',\s*500/', $routingBlock );
	}


	private function routingTryBlock( string $source ): string {
		$start = strpos( $source, 'new \gcgov\framework\router()' );
		$end   = strpos( $source, '\app\router::_after();' );
		self::assertIsInt( $start );
		self::assertIsInt( $end );

		return substr( $source, $start, $end - $start );
	}

}
