<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\jsonPatchEmbeddable;

#[CoversClass(jsonPatchEmbeddable::class)]
final class JsonPatchEmbeddableTest extends TestCase {

	public function testDefaultsAreEmpty(): void {
		$patch = new jsonPatchEmbeddable();
		$this->assertSame( '', $patch->op );
		$this->assertSame( '', $patch->path );
		$this->assertNull( $patch->value );
	}

	public function testFieldsAreMutable(): void {
		$patch = new jsonPatchEmbeddable();
		$patch->op = 'replace';
		$patch->path = '/name';
		$patch->value = 'newName';

		$this->assertSame( 'replace', $patch->op );
		$this->assertSame( '/name', $patch->path );
		$this->assertSame( 'newName', $patch->value );
	}

	public function testValueCanHoldDifferentTypes(): void {
		$patch = new jsonPatchEmbeddable();
		$patch->value = 42;
		$this->assertSame( 42, $patch->value );

		$patch->value = [ 'k' => 'v' ];
		$this->assertSame( [ 'k' => 'v' ], $patch->value );

		$patch->value = null;
		$this->assertNull( $patch->value );
	}

	public function testExtendsFrameworkEmbeddable(): void {
		$this->assertTrue(
			is_subclass_of( jsonPatchEmbeddable::class, \gcgov\framework\services\mongodb\embeddable::class )
		);
	}

}
