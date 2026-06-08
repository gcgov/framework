<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Interfaces;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use gcgov\framework\interfaces;

/**
 * Structural smoke tests for every published framework interface. They verify
 * the interface exists in the published namespace and exposes the methods
 * documented in the readme — guarding against accidental rename or removal.
 */
final class InterfacesTest extends TestCase {

	#[DataProvider('interfaceMethodMatrix')]
	public function testInterfaceDeclaresExpectedMethods( string $interface, array $methods ): void {
		$this->assertTrue( interface_exists( $interface ), "Missing interface: $interface" );
		$reflection = new \ReflectionClass( $interface );
		foreach ( $methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"$interface is missing method $method"
			);
		}
	}

	public static function interfaceMethodMatrix(): array {
		return [
			'app' => [ interfaces\app::class, [ 'registerFrameworkServiceNamespaces' ] ],
			'router' => [ interfaces\router::class, [ 'getRoutes', 'authentication' ] ],
			'controller' => [ interfaces\controller::class, [] ],
			'render' => [ interfaces\render::class, [
				'processModelException',
				'processControllerException',
				'processRouteException',
				'processSystemErrorException',
			] ],
			'jsonDeserialize' => [ interfaces\jsonDeserialize::class, [] ],
			'_controllerResponse' => [ interfaces\_controllerResponse::class, [] ],
			'_controllerDataResponse' => [ interfaces\_controllerDataResponse::class, [
				'getData', 'setData', 'getHttpStatus', 'setHttpStatus',
			] ],
			'_controllerFileResponse' => [ interfaces\_controllerFileResponse::class, [] ],
			'_controllerPdfResponse' => [ interfaces\_controllerPdfResponse::class, [] ],
			'_controllerViewResponse' => [ interfaces\_controllerViewResponse::class, [] ],
			'authUser' => [ interfaces\auth\user::class, [
				'getId', 'getName', 'getUsername', 'getPassword', 'getEmail',
				'getRoles', 'getActive', 'getFromOauth',
			] ],
			'dbGetResult' => [ interfaces\dbGetResult::class, [
				'getData', 'setData', 'getPage', 'setPage', 'getLimit', 'setLimit',
				'getSkip', 'getCount', 'getTotalDocumentCount', 'getTotalPageCount',
			] ],
			'lifecycleBefore' => [ interfaces\lifecycle\before::class, [ '_before' ] ],
			'lifecycleAfter' => [ interfaces\lifecycle\after::class, [ '_after' ] ],
			'eventDefinitions' => [ interfaces\event\definitions::class, [] ],
			'eventListeners' => [ interfaces\event\listeners::class, [] ],
		];
	}

	public function testSingletonIsAbstractClassNotInterface(): void {
		$reflection = new \ReflectionClass( interfaces\singleton::class );
		$this->assertTrue( $reflection->isAbstract() );
		$this->assertTrue( $reflection->hasMethod( 'getInstance' ) );
	}

}
