<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\exceptions\databaseException;
use gcgov\framework\services\mongodb\exceptions\dispatchException;

#[CoversClass(databaseException::class)]
#[CoversClass(dispatchException::class)]
final class MongoExceptionsTest extends TestCase {

	public function testDatabaseExceptionExtendsLogicException(): void {
		$this->assertInstanceOf( \LogicException::class, new databaseException( 'msg' ) );
	}

	public function testDispatchExceptionExtendsLogicException(): void {
		$this->assertInstanceOf( \LogicException::class, new dispatchException( 'msg' ) );
	}

	public function testDatabaseExceptionPreservesMessageAndCode(): void {
		$prev = new \RuntimeException( 'inner' );
		$ex = new databaseException( 'outer', 500, $prev );
		$this->assertSame( 'outer', $ex->getMessage() );
		$this->assertSame( 500, $ex->getCode() );
		$this->assertSame( $prev, $ex->getPrevious() );
	}

	public function testDispatchExceptionPreservesMessageAndCode(): void {
		$prev = new \RuntimeException( 'inner' );
		$ex = new dispatchException( 'outer', 500, $prev );
		$this->assertSame( 'outer', $ex->getMessage() );
		$this->assertSame( 500, $ex->getCode() );
		$this->assertSame( $prev, $ex->getPrevious() );
	}

	public function testDefaultCodeIsZero(): void {
		$this->assertSame( 0, ( new databaseException( 'msg' ) )->getCode() );
		$this->assertSame( 0, ( new dispatchException( 'msg' ) )->getCode() );
	}

}
