<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\controllerFileResponse;
use gcgov\framework\models\controllerFileBase64EncodedContentResponse;
use gcgov\framework\models\controllerPdfResponse;
use gcgov\framework\exceptions\controllerException;

#[CoversClass(controllerFileResponse::class)]
#[CoversClass(controllerFileBase64EncodedContentResponse::class)]
#[CoversClass(controllerPdfResponse::class)]
final class ControllerFileResponseTest extends TestCase {

	private string $tempFile = '';

	protected function setUp(): void {
		$this->tempFile = tempnam( sys_get_temp_dir(), 'test' );
		file_put_contents( $this->tempFile, 'sample contents' );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->tempFile ) ) {
			unlink( $this->tempFile );
		}
	}

	public function testFileResponseAcceptsExistingFile(): void {
		$response = new controllerFileResponse( $this->tempFile );
		$this->assertSame( $this->tempFile, $response->getFilePathname() );
		$this->assertNotSame( '', $response->getContentType() );
	}

	public function testFileResponseThrowsForMissingFile(): void {
		$this->expectException( controllerException::class );
		new controllerFileResponse( '/path/that/does/not/exist' );
	}

	public function testSetFilePathnameUpdatesContentType(): void {
		$response = new controllerFileResponse( $this->tempFile );
		$other = tempnam( sys_get_temp_dir(), 'oth' );
		file_put_contents( $other, 'x' );
		try {
			$response->setFilePathname( $other );
			$this->assertSame( $other, $response->getFilePathname() );
		}
		finally {
			unlink( $other );
		}
	}

	public function testBase64ResponseStoresProvidedData(): void {
		$response = new controllerFileBase64EncodedContentResponse(
			'application/octet-stream',
			'aGVsbG8=',
			'sample.bin'
		);
		$this->assertSame( 'application/octet-stream', $response->getContentType() );
		$this->assertSame( 'aGVsbG8=', $response->getBase64EncodedContent() );
		$this->assertSame( 'sample.bin', $response->getFilePathname() );
	}

	public function testBase64ResponseAcceptsHeaders(): void {
		$response = new controllerFileBase64EncodedContentResponse(
			'application/json',
			'',
			'name.json',
			[ new \gcgov\framework\models\controllerResponseHeader( 'X-Test', '1' ) ]
		);
		$this->assertCount( 1, $response->getHeaders() );
	}

	public function testPdfResponseAcceptsPdfFile(): void {
		$response = new controllerPdfResponse( $this->tempFile );
		$response->setContentType( 'application/pdf' );
		$this->assertSame( 'application/pdf', $response->getContentType() );
	}

	public function testPdfResponseRejectsNonPdfContentType(): void {
		$response = new controllerPdfResponse( $this->tempFile );
		$this->expectException( controllerException::class );
		$response->setContentType( 'application/json' );
	}

}
