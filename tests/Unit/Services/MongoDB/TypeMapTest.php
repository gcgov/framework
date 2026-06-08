<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\typeMap;
use gcgov\framework\services\mongodb\typeMapType;
use gcgov\framework\services\mongodb\typeMapFactory;
use gcgov\framework\services\mongodb\models\audit;

#[CoversClass(typeMap::class)]
#[CoversClass(typeMapFactory::class)]
final class TypeMapTest extends TestCase {

	protected function setUp(): void {
		// Reset factory caches between tests.
		$tm = new \ReflectionProperty( typeMapFactory::class, 'typeMaps' );
		$tm->setValue( null, [] );
		$mm = new \ReflectionProperty( typeMapFactory::class, 'modelTypeMaps' );
		$mm->setValue( null, [] );
		$fetched = new \ReflectionProperty( typeMapFactory::class, 'allModelTypeMapsFetched' );
		$fetched->setValue( null, [] );
	}

	public function testTypeMapForRootModelMarksItAsModel(): void {
		$map = new typeMap( '\\' . audit::class, typeMapType::serialize );
		$this->assertSame( '\\' . audit::class, $map->root );
		$this->assertTrue( $map->model );
	}

	public function testTypeMapForRootModelDiscoversCollectionName(): void {
		$map = new typeMap( '\\' . audit::class, typeMapType::serialize );
		$this->assertSame( 'audit', $map->collection );
	}

	public function testTypeMapToArrayHasRootAndFieldPaths(): void {
		$map = new typeMap( '\\' . audit::class, typeMapType::serialize );
		$array = $map->toArray();
		$this->assertArrayHasKey( 'root', $array );
		$this->assertArrayHasKey( 'fieldPaths', $array );
		$this->assertIsArray( $array[ 'fieldPaths' ] );
	}

	public function testTypeMapDefaultsToSerializeType(): void {
		$map = new typeMap( '\\' . audit::class );
		$this->assertSame( typeMapType::serialize, $map->type );
	}

	public function testTypeMapAcceptsUnserializeType(): void {
		$map = new typeMap( '\\' . audit::class, typeMapType::unserialize );
		$this->assertSame( typeMapType::unserialize, $map->type );
	}

	public function testTypeMapFactoryReturnsCachedInstance(): void {
		$a = typeMapFactory::get( '\\' . audit::class );
		$b = typeMapFactory::get( '\\' . audit::class );
		$this->assertSame( $a, $b );
	}

	public function testTypeMapFactoryReturnsTypeMapInstance(): void {
		$result = typeMapFactory::get( '\\' . audit::class );
		$this->assertInstanceOf( typeMap::class, $result );
	}

	public function testTypeMapFactoryCachesByTypeAndContext(): void {
		$serializeMap = typeMapFactory::get( '\\' . audit::class, typeMapType::serialize );
		$unserializeMap = typeMapFactory::get( '\\' . audit::class, typeMapType::unserialize );
		$this->assertNotSame( $serializeMap, $unserializeMap );
	}

	public function testTypeMapForNonEmbeddableClassReturnsEmpty(): void {
		// stdClass does not extend embeddable, so generate returns early.
		$map = new typeMap( '\\stdClass' );
		$this->assertFalse( $map->model );
		$this->assertSame( [], $map->fieldPaths );
	}

}
