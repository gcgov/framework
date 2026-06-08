<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\typeHelpers;

#[CoversClass(typeHelpers::class)]
final class TypeHelpersTest extends TestCase {

	public function testClassNameToFqnPrependsLeadingBackslash(): void {
		$this->assertSame( '\\app\\models\\widget', typeHelpers::classNameToFqn( 'app\\models\\widget' ) );
	}

	public function testClassNameToFqnPreservesExistingLeadingBackslash(): void {
		$this->assertSame( '\\app\\models\\widget', typeHelpers::classNameToFqn( '\\app\\models\\widget' ) );
	}

	public function testClassNameToFqnNormalisesMultipleLeadingBackslashes(): void {
		$this->assertSame( '\\widget', typeHelpers::classNameToFqn( '\\\\\\widget' ) );
	}

	public function testGetVarTypeFromDocCommentMatchesSimpleType(): void {
		$comment = '/** @var string $foo */';
		$this->assertSame( 'string', typeHelpers::getVarTypeFromDocComment( $comment ) );
	}

	public function testGetVarTypeFromDocCommentReadsArrayType(): void {
		$comment = '/** @var string[] $bar */';
		$this->assertSame( 'string', typeHelpers::getVarTypeFromDocComment( $comment ) );
	}

	public function testGetVarTypeFromDocCommentFallsBackToArrayWhenNoVarTag(): void {
		$comment = '/** No type info here */';
		$this->assertSame( 'array', typeHelpers::getVarTypeFromDocComment( $comment ) );
	}

	public function testGetVarTypeFromDocCommentCachesByCommentString(): void {
		$comment = '/** @var int $x */';
		$result1 = typeHelpers::getVarTypeFromDocComment( $comment );
		$result2 = typeHelpers::getVarTypeFromDocComment( $comment );
		$this->assertSame( $result1, $result2 );
		$this->assertSame( 'int', $result1 );
	}

	public function testClassNameToFqnCachesValues(): void {
		typeHelpers::classNameToFqn( '\\some\\class' );
		typeHelpers::classNameToFqn( '\\some\\class' );

		$prop = new \ReflectionProperty( typeHelpers::class, 'classNameToFqnConversionCache' );
		$cache = $prop->getValue();
		$this->assertArrayHasKey( '\\some\\class', $cache );
	}

}
