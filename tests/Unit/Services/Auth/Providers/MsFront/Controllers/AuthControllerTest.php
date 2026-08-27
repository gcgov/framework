<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\MsFront\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\auth\providers\msFront\controllers\auth;

#[CoversClass(auth::class)]
final class AuthControllerTest extends TestCase {

	public function testControllerImplementsFrameworkControllerInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\controller::class,
			class_implements( auth::class ) ?: []
		);
	}

	public function testConstructorRequiresNoArguments(): void {
		$reflection = new \ReflectionClass( auth::class );
		$constructor = $reflection->getConstructor();
		$this->assertNotNull( $constructor );
		$this->assertSame( 0, $constructor->getNumberOfRequiredParameters() );
		$this->assertInstanceOf( auth::class, new auth() );
	}


	public function testMicrosoftMethodReturnsControllerDataResponseType(): void {
		$reflection = new \ReflectionMethod( auth::class, 'microsoft' );
		$this->assertSame(
			\gcgov\framework\models\controllerDataResponse::class,
			(string) $reflection->getReturnType()
		);
	}


	public function testLookupUserMicrosoftTokenInfoIsPrivate(): void {
		$reflection = new \ReflectionMethod( auth::class, 'lookupUserMicrosoftTokenInfo' );
		$this->assertTrue( $reflection->isPrivate() );
	}

	public function testLifecycleHooksReturnVoid(): void {
		auth::_before();
		auth::_after();

		$reflection = new \ReflectionClass( auth::class );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_before' )->getReturnType() );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_after' )->getReturnType() );
	}

}
