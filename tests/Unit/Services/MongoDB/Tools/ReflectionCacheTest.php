<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\reflectionCache;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheClass;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheProperty;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheAttributeTrait;
use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;

#[CoversClass(reflectionCache::class)]
#[CoversClass(reflectionCacheClass::class)]
#[CoversClass(reflectionCacheProperty::class)]
#[CoversTrait(reflectionCacheAttributeTrait::class)]
final class ReflectionCacheTest extends TestCase {

	private string $tempDir = '';

	protected function setUp(): void {
		// Reset in-memory cache between tests.
		$prop = new \ReflectionProperty( reflectionCache::class, 'memory' );
		$prop->setValue( null, [] );

		// Point config::getTempDir at our temp dir so disk cache writes succeed.
		$this->tempDir = sys_get_temp_dir() . '/gcgov-framework-rc-tests-' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		$rootProp = new \ReflectionProperty( \gcgov\framework\config::class, 'rootDir' );
		$rootProp->setValue( null, $this->tempDir );
	}

	public function testGetReflectionClassReturnsCacheObject(): void {
		$result = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$this->assertInstanceOf( reflectionCacheClass::class, $result );
		$this->assertSame( ReflectionCacheTestSample::class, $result->classFQN );
	}

	public function testGetReflectionClassCachesInMemory(): void {
		$first = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$second = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$this->assertSame( $first, $second );
	}

	public function testSampleClassPropertiesAreIndexed(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$this->assertArrayHasKey( 'name', $cache->properties );
		$this->assertArrayHasKey( 'secret', $cache->properties );

		$this->assertInstanceOf( reflectionCacheProperty::class, $cache->properties[ 'name' ] );
	}

	public function testLabelAttributeRecordedOnProperty(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$nameProp = $cache->properties[ 'name' ];
		$this->assertTrue( $nameProp->hasAttribute( label::class ) );
	}

	public function testExcludeBsonSerializeFlagIsSetWhenAttributePresent(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$secretProp = $cache->properties[ 'secret' ];
		$this->assertTrue( $secretProp->excludeBsonSerialize );
	}

	public function testGetAttributeInstanceConstructsRealAttribute(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$nameProp = $cache->properties[ 'name' ];
		$instance = $nameProp->getAttributeInstance( label::class );
		$this->assertInstanceOf( label::class, $instance );
		$this->assertSame( 'My Name', $instance->label );
	}

	public function testGetPropertiesWithAttributeReturnsOnlyMatching(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$matched = $cache->getPropertiesWithAttribute( excludeBsonSerialize::class );
		$this->assertArrayHasKey( 'secret', $matched );
		$this->assertArrayNotHasKey( 'name', $matched );
	}

	public function testGetAttributeInstancesByPropertyNameYieldsLabelInstances(): void {
		$cache = reflectionCache::getReflectionClass( ReflectionCacheTestSample::class );
		$instances = $cache->getAttributeInstancesByPropertyName( label::class );
		$this->assertArrayHasKey( 'name', $instances );
		$this->assertInstanceOf( label::class, $instances[ 'name' ] );
	}

}

class ReflectionCacheTestSample {
	#[label('My Name')]
	public string $name = '';

	#[label('Secret')]
	#[excludeBsonSerialize]
	public string $secret = '';
}
