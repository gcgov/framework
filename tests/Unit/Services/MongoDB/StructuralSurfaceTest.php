<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\embeddable;
use gcgov\framework\services\mongodb\model;
use gcgov\framework\services\mongodb\dispatcher;
use gcgov\framework\services\mongodb\factory;
use gcgov\framework\services\mongodb\tools\mdb;
use gcgov\framework\services\mongodb\tools\auditManager;

/**
 * Structural smoke tests for framework-internal classes that are excluded from
 * PHPStan static analysis (due to late-static-binding hook patterns) but still
 * expose a public API the rest of the framework relies on. These tests verify
 * the contract — type hierarchy, expected methods, signatures — without
 * exercising the MongoDB driver.
 */
#[CoversClass(embeddable::class)]
#[CoversClass(model::class)]
#[CoversClass(dispatcher::class)]
#[CoversClass(factory::class)]
#[CoversClass(mdb::class)]
#[CoversClass(auditManager::class)]
final class StructuralSurfaceTest extends TestCase {

	public function testEmbeddableIsAbstract(): void {
		$this->assertTrue( ( new \ReflectionClass( embeddable::class ) )->isAbstract() );
	}

	public function testEmbeddableImplementsPersistable(): void {
		$implements = class_implements( embeddable::class ) ?: [];
		$this->assertContains( \MongoDB\BSON\Persistable::class, $implements );
	}

	public function testEmbeddableExtendsJsonDeserialize(): void {
		$this->assertTrue(
			is_subclass_of( embeddable::class, \andrewsauder\jsonDeserialize\jsonDeserialize::class )
		);
	}

	public function testModelExtendsFactory(): void {
		$this->assertTrue( is_subclass_of( model::class, factory::class ) );
	}

	public function testFactoryExtendsDispatcher(): void {
		$this->assertTrue( is_subclass_of( factory::class, dispatcher::class ) );
	}

	public function testDispatcherExtendsEmbeddable(): void {
		$this->assertTrue( is_subclass_of( dispatcher::class, embeddable::class ) );
	}

	public function testModelDeclaresExpectedHelperMethods(): void {
		// model.php defines the helper methods used by every concrete model.
		$this->assertTrue( method_exists( model::class, '_getCollectionName' ) );
		$this->assertTrue( method_exists( model::class, '_getHumanName' ) );
		$this->assertTrue( method_exists( model::class, 'getIndexes' ) );
	}

	public function testFactoryDeclaresCoreCrudMethods(): void {
		// These late-binding hooks are why the file is excluded from PHPStan,
		// but they must continue to exist for subclasses to override.
		$this->assertTrue( method_exists( factory::class, 'save' ) );
		$this->assertTrue( method_exists( factory::class, 'saveMany' ) );
		$this->assertTrue( method_exists( factory::class, 'delete' ) );
		$this->assertTrue( method_exists( factory::class, 'deleteMany' ) );
		$this->assertTrue( method_exists( factory::class, 'deleteManyBy' ) );
		$this->assertTrue( method_exists( factory::class, 'getOne' ) );
		$this->assertTrue( method_exists( factory::class, 'getOneBy' ) );
		$this->assertTrue( method_exists( factory::class, 'getAll' ) );
		$this->assertTrue( method_exists( factory::class, 'getPagedResponse' ) );
		$this->assertTrue( method_exists( factory::class, 'countDocuments' ) );
		$this->assertTrue( method_exists( factory::class, 'aggregation' ) );
	}

	public function testDispatcherDeclaresEmbeddedActionMethods(): void {
		// _getCollectionName actually lives on model. dispatcher provides the
		// embedded-update / embedded-delete / cascade-delete helpers.
		$this->assertTrue( method_exists( dispatcher::class, '_getUpdateEmbeddedMongoActions' ) );
		$this->assertTrue( method_exists( dispatcher::class, '_updateEmbedded' ) );
		$this->assertTrue( method_exists( dispatcher::class, '_deleteEmbedded' ) );
		$this->assertTrue( method_exists( dispatcher::class, '_deleteCascade' ) );
		$this->assertTrue( method_exists( dispatcher::class, '_insertEmbedded' ) );
	}

	public function testMdbClassExists(): void {
		$this->assertTrue( class_exists( mdb::class ) );
		$this->assertTrue( ( new \ReflectionClass( mdb::class ) )->hasMethod( '__construct' ) );
	}

	public function testAuditManagerClassExists(): void {
		$this->assertTrue( class_exists( auditManager::class ) );
	}

}
