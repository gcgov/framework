<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerResponseHeader;
use gcgov\framework\exceptions\controllerException;

#[CoversClass(controllerDataResponse::class)]
final class ControllerDataResponseTest extends TestCase {

	public function testDefaultsToNullDataAndJsonContentType(): void {
		$response = new controllerDataResponse();
		$this->assertNull( $response->getData() );
		$this->assertSame( 'application/json', $response->getContentType() );
		$this->assertSame( 200, $response->getHttpStatus() );
	}

	public function testConstructorSetsData(): void {
		$data = [ 'a' => 1, 'b' => 2 ];
		$response = new controllerDataResponse( $data );
		$this->assertSame( $data, $response->getData() );
	}

	public function testSetDataReplacesData(): void {
		$response = new controllerDataResponse( [ 'old' => 1 ] );
		$response->setData( [ 'new' => 2 ] );
		$this->assertSame( [ 'new' => 2 ], $response->getData() );
	}

	public function testConstructorAcceptsHeaders(): void {
		$response = new controllerDataResponse( [], [
			new controllerResponseHeader( 'X-Test', '1' ),
		] );
		$this->assertCount( 1, $response->getHeaders() );
	}

	public function testSetContentTypeAcceptsSupportedTypes(): void {
		$response = new controllerDataResponse();
		foreach ( controllerDataResponse::SupportedContentTypes as $type ) {
			$response->setContentType( $type );
			$this->assertSame( $type, $response->getContentType() );
		}
	}

	public function testSetContentTypeRejectsUnsupportedType(): void {
		$response = new controllerDataResponse();
		$this->expectException( controllerException::class );
		$response->setContentType( 'application/xml' );
	}

	public function testSupportedContentTypesIncludesJsonAndPlain(): void {
		$this->assertContains( 'application/json', controllerDataResponse::SupportedContentTypes );
		$this->assertContains( 'text/plain', controllerDataResponse::SupportedContentTypes );
	}

	public function testDataCanHoldScalarAndObjectValues(): void {
		$response = new controllerDataResponse( 42 );
		$this->assertSame( 42, $response->getData() );

		$obj = new \stdClass();
		$response->setData( $obj );
		$this->assertSame( $obj, $response->getData() );
	}

}
