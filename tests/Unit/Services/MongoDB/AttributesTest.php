<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\attributes\autoIncrement;
use gcgov\framework\services\mongodb\attributes\collection;
use gcgov\framework\services\mongodb\attributes\deleteCascade;
use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeFromTypemapWhenThisClassNotRoot;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonSerialize;
use gcgov\framework\services\mongodb\attributes\foreignKey;
use gcgov\framework\services\mongodb\attributes\includeMeta;
use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\attributes\redact;
use gcgov\framework\services\mongodb\attributes\visibility;

#[CoversClass(autoIncrement::class)]
#[CoversClass(collection::class)]
#[CoversClass(deleteCascade::class)]
#[CoversClass(excludeBsonSerialize::class)]
#[CoversClass(excludeBsonUnserialize::class)]
#[CoversClass(excludeFromTypemapWhenThisClassNotRoot::class)]
#[CoversClass(excludeJsonDeserialize::class)]
#[CoversClass(excludeJsonSerialize::class)]
#[CoversClass(foreignKey::class)]
#[CoversClass(includeMeta::class)]
#[CoversClass(label::class)]
#[CoversClass(redact::class)]
#[CoversClass(visibility::class)]
final class AttributesTest extends TestCase {

	public function testAutoIncrementDefaults(): void {
		$a = new autoIncrement();
		$this->assertSame( '', $a->groupByPropertyName );
		$this->assertSame( '', $a->groupByMethodName );
		$this->assertSame( '', $a->countFormatMethod );
	}

	public function testAutoIncrementWithArguments(): void {
		$a = new autoIncrement( 'g1', 'm1', 'cf' );
		$this->assertSame( 'g1', $a->groupByPropertyName );
		$this->assertSame( 'm1', $a->groupByMethodName );
		$this->assertSame( 'cf', $a->countFormatMethod );
	}

	public function testCollectionAttributeStoresNames(): void {
		$c = new collection( 'widgets', 'widget', 'widgets' );
		$this->assertSame( 'widgets', $c->collection );
		$this->assertSame( 'widget', $c->humanName );
		$this->assertSame( 'widgets', $c->humanNamePlural );
	}

	public function testDeleteCascadeInstantiates(): void {
		$this->assertInstanceOf( deleteCascade::class, new deleteCascade() );
	}

	public function testExcludeAttributesInstantiate(): void {
		$this->assertInstanceOf( excludeBsonSerialize::class, new excludeBsonSerialize() );
		$this->assertInstanceOf( excludeBsonUnserialize::class, new excludeBsonUnserialize() );
		$this->assertInstanceOf( excludeFromTypemapWhenThisClassNotRoot::class, new excludeFromTypemapWhenThisClassNotRoot() );
		$this->assertInstanceOf( excludeJsonDeserialize::class, new excludeJsonDeserialize() );
		$this->assertInstanceOf( excludeJsonSerialize::class, new excludeJsonSerialize() );
	}

	public function testForeignKeyStoresPropertyAndFilter(): void {
		$fk = new foreignKey( 'related', [ 'active' => true ] );
		$this->assertSame( 'related', $fk->propertyName );
		$this->assertSame( [ 'active' => true ], $fk->embeddedObjectFilter );
	}

	public function testForeignKeyFilterDefaultsToEmpty(): void {
		$fk = new foreignKey( 'p' );
		$this->assertSame( [], $fk->embeddedObjectFilter );
	}

	public function testIncludeMetaDefaultsToTrue(): void {
		$im = new includeMeta();
		$this->assertTrue( $im->includeMeta );
	}

	public function testIncludeMetaAcceptsFalse(): void {
		$im = new includeMeta( false );
		$this->assertFalse( $im->includeMeta );
	}

	public function testLabelStoresProvidedString(): void {
		$l = new label( 'Display Name' );
		$this->assertSame( 'Display Name', $l->label );
	}

	public function testRedactDefaults(): void {
		$r = new redact();
		$this->assertSame( [], $r->redactIfUserHasAnyRoles );
		$this->assertSame( [], $r->redactIfUserHasAllRoles );
	}

	public function testRedactStoresProvidedRoles(): void {
		$r = new redact( [ 'A' ], [ 'B', 'C' ] );
		$this->assertSame( [ 'A' ], $r->redactIfUserHasAnyRoles );
		$this->assertSame( [ 'B', 'C' ], $r->redactIfUserHasAllRoles );
	}

	public function testVisibilityDefaults(): void {
		$v = new visibility();
		$this->assertTrue( $v->visible );
		$this->assertSame( [], $v->visibilityGroups );
		$this->assertFalse( $v->valueIsVisibilityGroup );
	}

	public function testVisibilityWithGroups(): void {
		$v = new visibility( false, [ 'group-a' ], true );
		$this->assertFalse( $v->visible );
		$this->assertSame( [ 'group-a' ], $v->visibilityGroups );
		$this->assertTrue( $v->valueIsVisibilityGroup );
	}

	public function testAllAttributesHaveAttributeReflection(): void {
		$classes = [
			autoIncrement::class, collection::class, deleteCascade::class,
			excludeBsonSerialize::class, excludeBsonUnserialize::class,
			excludeFromTypemapWhenThisClassNotRoot::class,
			excludeJsonDeserialize::class, excludeJsonSerialize::class,
			foreignKey::class, includeMeta::class, label::class,
			redact::class, visibility::class,
		];
		foreach ( $classes as $class ) {
			$reflection = new \ReflectionClass( $class );
			$attributes = $reflection->getAttributes( \Attribute::class );
			$this->assertNotEmpty( $attributes, "$class is missing #[Attribute]" );
		}
	}

	public function testCollectionAttributeTargetsClass(): void {
		$reflection = new \ReflectionClass( collection::class );
		$attribute = $reflection->getAttributes( \Attribute::class )[0];
		$instance = $attribute->newInstance();
		$this->assertTrue( ( $instance->flags & \Attribute::TARGET_CLASS ) !== 0 );
	}

	public function testLabelAttributeTargetsProperty(): void {
		$reflection = new \ReflectionClass( label::class );
		$attribute = $reflection->getAttributes( \Attribute::class )[0];
		$instance = $attribute->newInstance();
		$this->assertTrue( ( $instance->flags & \Attribute::TARGET_PROPERTY ) !== 0 );
	}

}
