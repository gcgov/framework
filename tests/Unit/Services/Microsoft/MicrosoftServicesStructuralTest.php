<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Microsoft;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\microsoft\auth;
use gcgov\framework\services\microsoft\files;
use gcgov\framework\services\microsoft\mail;

/**
 * Structural tests for the deprecated Microsoft service classes. They wrap
 * Microsoft Graph and OAuth2 calls that aren't reachable from the test
 * sandbox, so we verify their public surface stays intact instead.
 */
#[CoversClass(auth::class)]
#[CoversClass(files::class)]
#[CoversClass(mail::class)]
final class MicrosoftServicesStructuralTest extends TestCase {

	public function testAuthClassDeclaresExpectedPublicMethods(): void {
		$reflection = new \ReflectionClass( auth::class );
		$this->assertTrue( $reflection->hasMethod( '__construct' ) );
		$this->assertTrue( $reflection->hasMethod( 'getAccessToken' ) );
		$this->assertTrue( $reflection->hasMethod( 'verify' ) );
		$this->assertTrue( $reflection->hasMethod( 'getApplicationAccessToken' ) );
	}

	public function testAuthGetAccessTokenSignature(): void {
		$method = new \ReflectionMethod( auth::class, 'getAccessToken' );
		$params = $method->getParameters();
		$this->assertSame( 'suppliedToken', $params[0]->getName() );
		$this->assertSame( 'string', (string) $params[0]->getType() );
	}

	public function testAuthVerifyReturnsTokenInformation(): void {
		$method = new \ReflectionMethod( auth::class, 'verify' );
		$this->assertSame(
			\gcgov\framework\services\microsoft\components\tokenInfomation::class,
			(string) $method->getReturnType()
		);
	}

	public function testFilesClassDeclaresExpectedMethods(): void {
		$reflection = new \ReflectionClass( files::class );
		foreach ( [ 'list', 'getFile', 'getFileById', 'moveItem', 'renameItem', 'downloadFile', 'upload', 'delete' ] as $method ) {
			$this->assertTrue( $reflection->hasMethod( $method ), "files class missing $method" );
		}
	}

	public function testFilesUploadReturnsUploadComponent(): void {
		$method = new \ReflectionMethod( files::class, 'upload' );
		$this->assertSame(
			\gcgov\framework\services\microsoft\components\upload::class,
			(string) $method->getReturnType()
		);
	}

	public function testFilesGetFileReturnsDriveItem(): void {
		$method = new \ReflectionMethod( files::class, 'getFile' );
		$this->assertSame(
			\Microsoft\Graph\Model\DriveItem::class,
			(string) $method->getReturnType()
		);
	}

	public function testMailClassDeclaresExpectedMethods(): void {
		$reflection = new \ReflectionClass( mail::class );
		$this->assertTrue( $reflection->hasMethod( 'addAttachment' ) );
		$this->assertTrue( $reflection->hasMethod( 'send' ) );
	}

	public function testMailSendAcceptsToAsStringOrArray(): void {
		$method = new \ReflectionMethod( mail::class, 'send' );
		$toParam = $method->getParameters()[0];
		$type = $toParam->getType();
		$this->assertNotNull( $type );
		$this->assertStringContainsString( 'string', (string) $type );
		$this->assertStringContainsString( 'array', (string) $type );
	}

}
