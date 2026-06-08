<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\controllerViewResponse;

#[CoversClass(controllerViewResponse::class)]
final class ControllerViewResponseTest extends TestCase {

	public function testConstructorAssignsViewAndVars(): void {
		$response = new controllerViewResponse( '/path/to/view.phtml', [ 'title' => 'Hi' ] );
		$this->assertSame( '/path/to/view.phtml', $response->getView() );
		$this->assertSame( [ 'title' => 'Hi' ], $response->getVars() );
	}

	public function testSetViewReplacesPath(): void {
		$response = new controllerViewResponse( 'a.phtml', [] );
		$response->setView( 'b.phtml' );
		$this->assertSame( 'b.phtml', $response->getView() );
	}

	public function testSetVarsReplacesAllVars(): void {
		$response = new controllerViewResponse( 'view.phtml', [ 'a' => 1 ] );
		$response->setVars( [ 'b' => 2 ] );
		$this->assertSame( [ 'b' => 2 ], $response->getVars() );
	}

	public function testHeadersForwardedToParent(): void {
		$response = new controllerViewResponse(
			'view.phtml',
			[],
			[ new \gcgov\framework\models\controllerResponseHeader( 'X-Test', '1' ) ]
		);
		$this->assertCount( 1, $response->getHeaders() );
	}

}
