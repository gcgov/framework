<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Controllers;

use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\services\auth\controllers\auth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * jwks and fileToken are provider-independent — both providers issue framework JWTs
 * signed by the same keys — so they live here rather than once per provider. These
 * assertions were previously duplicated across the two auth packages.
 */
#[CoversClass( auth::class )]
final class AuthControllerTest extends TestCase {

	public function testImplementsControllerInterface(): void {
		$this->assertContains( controller::class, class_implements( auth::class ) ?: [] );
	}


	public function testConstructorRequiresNoArguments(): void {
		$constructor = ( new \ReflectionClass( auth::class ) )->getConstructor();
		$this->assertNotNull( $constructor );
		$this->assertSame( 0, $constructor->getNumberOfRequiredParameters() );
		$this->assertInstanceOf( auth::class, new auth() );
	}


	public function testJwksReturnsControllerDataResponseType(): void {
		$this->assertSame( controllerDataResponse::class, (string)( new \ReflectionMethod( auth::class, 'jwks' ) )->getReturnType() );
	}


	public function testFileTokenReturnsControllerDataResponseType(): void {
		$this->assertSame( controllerDataResponse::class, (string)( new \ReflectionMethod( auth::class, 'fileToken' ) )->getReturnType() );
	}


	public function testLifecycleHooksReturnVoid(): void {
		auth::_before();
		auth::_after();
		$reflection = new \ReflectionClass( auth::class );
		$this->assertSame( 'void', (string)$reflection->getMethod( '_before' )->getReturnType() );
		$this->assertSame( 'void', (string)$reflection->getMethod( '_after' )->getReturnType() );
	}


	/** Neither provider should keep a copy of the shared endpoints. */
	public function testProvidersDoNotRedeclareTheSharedEndpoints(): void {
		foreach( [ \gcgov\framework\services\auth\providers\oauth\controllers\auth::class,
		           \gcgov\framework\services\auth\providers\msFront\controllers\auth::class ] as $provider ) {
			$this->assertFalse( method_exists( $provider, 'jwks' ), $provider . ' still declares jwks()' );
			$this->assertFalse( method_exists( $provider, 'fileToken' ), $provider . ' still declares fileToken()' );
		}
	}

}
