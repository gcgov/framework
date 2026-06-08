<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\controllerResponse;
use gcgov\framework\models\controllerResponseHeader;

#[CoversClass(controllerResponse::class)]
#[CoversClass(controllerResponseHeader::class)]
final class ControllerResponseTest extends TestCase {

	public function testDefaultStatusIs200(): void {
		$response = new controllerResponse();
		$this->assertSame( 200, $response->getHttpStatus() );
	}

	public function testHeadersDefaultEmpty(): void {
		$this->assertSame( [], ( new controllerResponse() )->getHeaders() );
	}

	public function testSetHttpStatusUpdatesValue(): void {
		$response = new controllerResponse();
		$response->setHttpStatus( 418 );
		$this->assertSame( 418, $response->getHttpStatus() );
	}

	public function testConstructorAcceptsHeaders(): void {
		$header = new controllerResponseHeader( 'X-Test', '1' );
		$response = new controllerResponse( [ $header ] );
		$this->assertSame( [ $header ], $response->getHeaders() );
	}

	public function testAddHeaderAppendsHeader(): void {
		$response = new controllerResponse();
		$response->addHeader( 'X-Foo', 'bar' );

		$headers = $response->getHeaders();
		$this->assertCount( 1, $headers );
		$this->assertInstanceOf( controllerResponseHeader::class, $headers[0] );
	}

	public function testAddHeadersAppendsAllProvidedHeaders(): void {
		$response = new controllerResponse();
		$response->addHeader( 'X-First', 'a' );
		$response->addHeaders( [
			new controllerResponseHeader( 'X-Second', 'b' ),
			new controllerResponseHeader( 'X-Third', 'c' ),
		] );
		$this->assertCount( 3, $response->getHeaders() );
	}

	public function testSetHeadersReplacesAll(): void {
		$response = new controllerResponse();
		$response->addHeader( 'X-First', 'a' );

		$response->setHeaders( [ new controllerResponseHeader( 'X-Only', 'z' ) ] );
		$this->assertCount( 1, $response->getHeaders() );
	}

	public function testHeaderClassExposesName(): void {
		$header = new controllerResponseHeader( 'X-Foo', 'bar' );
		$reflection = new \ReflectionProperty( $header, 'name' );
		$this->assertSame( 'X-Foo', $reflection->getValue( $header ) );
	}

}
