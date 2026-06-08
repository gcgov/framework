<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\metaAttributeCache;
use gcgov\framework\services\mongodb\models\_meta\uiField;

#[CoversClass(metaAttributeCache::class)]
final class MetaAttributeCacheTest extends TestCase {

	protected function setUp(): void {
		$this->resetCache();
	}

	protected function tearDown(): void {
		$this->resetCache();
	}

	public function testGetFieldsForUnknownClassReturnsNull(): void {
		$this->assertNull( metaAttributeCache::getFields( '\\fake\\class' ) );
	}

	public function testGetLabelsForUnknownClassReturnsNull(): void {
		$this->assertNull( metaAttributeCache::getLabels( '\\fake\\class' ) );
	}

	public function testSetStoresLabelsAndFields(): void {
		$fields = [ 'name' => new uiField() ];
		$labels = [ 'name' => 'Name' ];

		metaAttributeCache::set( 'SomeClass', $labels, $fields );

		$this->assertSame( $labels, metaAttributeCache::getLabels( 'SomeClass' ) );
		$this->assertSame( $fields, metaAttributeCache::getFields( 'SomeClass' ) );
	}

	public function testGetAllLabelsCombinesAllStoredLabels(): void {
		metaAttributeCache::set( 'A', [ 'a' => 'Alpha' ], [] );
		metaAttributeCache::set( 'B', [ 'b' => 'Beta' ], [] );

		$all = metaAttributeCache::getAllLabels();
		$this->assertArrayHasKey( 'A', $all );
		$this->assertArrayHasKey( 'B', $all );
		$this->assertSame( [ 'a' => 'Alpha' ], $all[ 'A' ] );
	}

	public function testGetAllFieldsCombinesAllStoredFields(): void {
		$fields1 = [ 'x' => new uiField() ];
		metaAttributeCache::set( 'X', [], $fields1 );

		$all = metaAttributeCache::getAllFields();
		$this->assertArrayHasKey( 'X', $all );
		$this->assertSame( $fields1, $all[ 'X' ] );
	}

	public function testConstructorIsPrivate(): void {
		$this->assertTrue(
			( new \ReflectionMethod( metaAttributeCache::class, '__construct' ) )->isPrivate()
		);
	}

	public function testSleepReturnsEmptyArray(): void {
		$instance = ( new \ReflectionClass( metaAttributeCache::class ) )->newInstanceWithoutConstructor();
		$this->assertSame( [], $instance->__sleep() );
	}

	private function resetCache(): void {
		$fields = new \ReflectionProperty( metaAttributeCache::class, 'fields' );
		$fields->setValue( null, [] );
		$labels = new \ReflectionProperty( metaAttributeCache::class, 'labels' );
		$labels->setValue( null, [] );
	}

}
