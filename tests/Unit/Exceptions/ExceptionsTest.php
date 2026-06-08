<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use gcgov\framework\exceptions\configException;
use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\eventException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\exceptions\modelDocumentNotFoundException;
use gcgov\framework\exceptions\routeException;
use gcgov\framework\exceptions\serviceException;

#[CoversClass(configException::class)]
#[CoversClass(controllerException::class)]
#[CoversClass(eventException::class)]
#[CoversClass(modelException::class)]
#[CoversClass(modelDocumentNotFoundException::class)]
#[CoversClass(routeException::class)]
#[CoversClass(serviceException::class)]
final class ExceptionsTest extends TestCase {

	public function testConfigExceptionExtendsLogicException(): void {
		$this->assertInstanceOf( \LogicException::class, new configException( 'msg' ) );
	}

	public function testControllerExceptionExtendsException(): void {
		$this->assertInstanceOf( \Exception::class, new controllerException( 'msg' ) );
	}

	public function testEventExceptionExtendsLogicException(): void {
		$this->assertInstanceOf( \LogicException::class, new eventException( 'msg' ) );
	}

	public function testModelExceptionExtendsException(): void {
		$this->assertInstanceOf( \Exception::class, new modelException( 'msg' ) );
	}

	public function testRouteExceptionExtendsException(): void {
		$this->assertInstanceOf( \Exception::class, new routeException( 'msg' ) );
	}

	public function testServiceExceptionExtendsException(): void {
		$this->assertInstanceOf( \Exception::class, new serviceException( 'msg' ) );
	}

	public function testModelDocumentNotFoundExceptionExtendsModelException(): void {
		$ex = new modelDocumentNotFoundException( 'not found' );
		$this->assertInstanceOf( modelException::class, $ex );
		$this->assertSame( 404, $ex->getCode() );
	}

	#[DataProvider('exceptionClasses')]
	public function testExceptionAcceptsMessageCodeAndPrevious( string $exceptionClass ): void {
		$previous = new \RuntimeException( 'inner' );
		$ex = new $exceptionClass( 'outer', 500, $previous );

		$this->assertSame( 'outer', $ex->getMessage() );
		$this->assertSame( 500, $ex->getCode() );
		$this->assertSame( $previous, $ex->getPrevious() );
	}

	#[DataProvider('exceptionClasses')]
	public function testExceptionDefaultCodeIsZero( string $exceptionClass ): void {
		if ( $exceptionClass === modelDocumentNotFoundException::class ) {
			$this->markTestSkipped( 'modelDocumentNotFoundException has a fixed 404 code' );
		}
		$ex = new $exceptionClass( 'msg' );
		$this->assertSame( 0, $ex->getCode() );
	}

	public function testModelDocumentNotFoundExceptionAcceptsPrevious(): void {
		$previous = new \RuntimeException( 'inner' );
		$ex = new modelDocumentNotFoundException( 'gone', $previous );
		$this->assertSame( 'gone', $ex->getMessage() );
		$this->assertSame( 404, $ex->getCode() );
		$this->assertSame( $previous, $ex->getPrevious() );
	}

	public static function exceptionClasses(): array {
		return [
			[ configException::class ],
			[ controllerException::class ],
			[ eventException::class ],
			[ modelException::class ],
			[ routeException::class ],
			[ serviceException::class ],
		];
	}

}
