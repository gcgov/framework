<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\routeCatalog;
use gcgov\framework\router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(routeCatalog::class)]
#[CoversClass(router::class)]
final class RouteCatalogTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		// a minimal fake app tree so appContext::locate() succeeds; the actual
		// \app\app and \app\router classes are the stubs from tests/bootstrap.php
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-routecatalog-test-' . uniqid();
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );
	}

	protected function tearDown(): void {
		unlink( $this->tempRootDir . '/vendor/autoload.php' );
		unlink( $this->tempRootDir . '/app/app.php' );
		rmdir( $this->tempRootDir . '/vendor' );
		rmdir( $this->tempRootDir . '/app' );
		rmdir( $this->tempRootDir );
	}

	public function testGetMergedRoutesReturnsAppRoutes(): void {
		$routes = router::getMergedRoutes( [] );
		$this->assertCount( 3, $routes );
	}

	public function testGetCliRoutesFiltersToCliMethodOnly(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );

		$cliRoutes = routeCatalog::getCliRoutes( $context );

		$paths = array_map( fn( $route ) => $route->route, $cliRoutes );
		$this->assertSame( [ '/cli/cleanup', '/cli/report' ], $paths, 'expects CLI-only routes sorted by path, including routes with an array httpMethod containing CLI' );
	}

	public function testCliRouteDescriptionsSurface(): void {
		$context = appContext::locate( $this->tempRootDir );
		$this->assertNotNull( $context );

		$cliRoutes = routeCatalog::getCliRoutes( $context );

		$this->assertSame( 'Clean up temp records', $cliRoutes[0]->description );
	}

}
