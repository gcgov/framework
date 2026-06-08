<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use gcgov\framework\services\formatting;

final class FormattingTest extends TestCase {

	public function testFileNameReplacesIllegalCharacters(): void {
		// Illegal chars become the replacement char ('-' by default),
		// then runs of the replacement char are collapsed to a single space.
		$result = formatting::fileName( 'foo:bar*baz?qux.txt' );
		$this->assertSame( 'foo bar baz qux.txt', $result );
	}

	public function testFileNameForcesLowercaseByDefault(): void {
		$this->assertSame( 'mixedcase.txt', formatting::fileName( 'MixedCase.txt' ) );
	}

	public function testFileNameCanPreserveCase(): void {
		$this->assertSame( 'MixedCase.txt', formatting::fileName( 'MixedCase.txt', '-', false ) );
	}

	public function testFileNameStripsSpacesAndCollapsesReplacementRuns(): void {
		// Spaces are illegal by default and get replaced with '-', then '-'+
		// is collapsed back to a single space — net result matches the input
		// shape but lowercased.
		$this->assertSame( 'a file with spaces.txt', formatting::fileName( 'A file with spaces.txt' ) );
	}

	public function testFileNameLeavesSpacesAloneWhenReplaceSpaceFalse(): void {
		$this->assertSame( 'a file with spaces.txt', formatting::fileName( 'A file with spaces.txt', '-', true, false ) );
	}

	public function testXlsxTabNameStripsIllegalChars(): void {
		$this->assertSame( 'a b c d', formatting::xlsxTabName( 'a/b:c?d' ) );
	}

}
