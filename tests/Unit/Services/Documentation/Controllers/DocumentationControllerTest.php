<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Documentation\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\documentation\controllers\documentation;
use gcgov\framework\models\controllerDataResponse;

#[CoversClass(documentation::class)]
final class DocumentationControllerTest extends TestCase {

	protected function setUp(): void {
		$this->primeFrameworkAppDir();
	}

	public function testControllerImplementsFrameworkControllerInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\controller::class,
			class_implements( documentation::class ) ?: []
		);
	}

	public function testConstructorAcceptsNoArguments(): void {
		$reflection = new \ReflectionClass( documentation::class );
		$this->assertSame( 0, $reflection->getConstructor()?->getNumberOfRequiredParameters() );
		$this->assertInstanceOf( documentation::class, new documentation() );
	}

	public function testRoutesReturnsEmptyControllerDataResponse(): void {
		$response = ( new documentation() )->routes();
		$this->assertInstanceOf( controllerDataResponse::class, $response );
	}

	public function testYamlMethodIsMarkedNoReturn(): void {
		$reflection = new \ReflectionMethod( documentation::class, 'yaml' );
		$attributes = $reflection->getAttributes();
		$names = array_map( fn( \ReflectionAttribute $a ) => $a->getName(), $attributes );
		$this->assertContains( \JetBrains\PhpStorm\NoReturn::class, $names );
	}

	public function testGetScanDirectoriesReturnsExistingDirectories(): void {
		$controller = new documentation();
		$method = new \ReflectionMethod( $controller, 'getScanDirectories' );
		$directories = $method->invoke( $controller );

		$this->assertIsArray( $directories );
		foreach ( $directories as $dir ) {
			$this->assertIsString( $dir );
			$this->assertDirectoryExists( $dir );
		}
	}

	public function testGetExcludeDirectoriesFilesReturnsArray(): void {
		$controller = new documentation();
		$method = new \ReflectionMethod( $controller, 'getExcludeDirectoriesFiles' );
		$exclusions = $method->invoke( $controller );

		$this->assertIsArray( $exclusions );
	}

	public function testLifecycleHooksReturnVoid(): void {
		documentation::_before();
		documentation::_after();

		$reflection = new \ReflectionClass( documentation::class );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_before' )->getReturnType() );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_after' )->getReturnType() );
	}

	private function primeFrameworkAppDir(): void {
		// config::getAppDir() reflects on \app\app to derive the directory.
		// Use a temp dir for the stub so getScanDirectories can verify any
		// real-on-disk paths return false (and thus get filtered).
		if ( !class_exists( '\app\app' ) ) {
			eval( 'namespace app; class app {}' );
		}
	}

}
