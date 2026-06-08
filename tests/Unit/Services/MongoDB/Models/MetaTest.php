<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\models\_meta;
use gcgov\framework\services\mongodb\models\_meta\db;
use gcgov\framework\services\mongodb\models\_meta\ui;
use gcgov\framework\services\mongodb\models\_meta\uiField;
use gcgov\framework\services\mongodb\updateDeleteResult;

#[CoversClass(_meta::class)]
#[CoversClass(db::class)]
#[CoversClass(ui::class)]
#[CoversClass(uiField::class)]
final class MetaTest extends TestCase {

	public function testMetaConstructorWithoutClassNameInitializesUi(): void {
		$meta = new _meta();
		$this->assertInstanceOf( ui::class, $meta->ui );
		$this->assertSame( [], $meta->fields );
		$this->assertSame( [], $meta->activeVisibilityGroups );
		$this->assertNull( $meta->db );
		$this->assertSame( 0.0, $meta->score );
	}

	public function testJsonSerializeReturnsUiAtLeast(): void {
		$meta = new _meta();
		$result = $meta->jsonSerialize();
		$this->assertArrayHasKey( 'ui', $result );
		$this->assertSame( $meta->ui, $result[ 'ui' ] );
	}

	public function testSetDbPopulatesDbModelAndExportsIt(): void {
		$meta = new _meta();
		$meta->setDb( new updateDeleteResult() );

		$result = $meta->jsonSerialize();
		$this->assertArrayHasKey( 'db', $result );
		$this->assertInstanceOf( db::class, $result[ 'db' ] );
	}

	public function testScoreExportedOnlyWhenNonZero(): void {
		$meta = new _meta();
		$this->assertArrayNotHasKey( 'score', $meta->jsonSerialize() );

		$meta->score = 1.5;
		$this->assertArrayHasKey( 'score', $meta->jsonSerialize() );
	}

	public function testDbModelStoresUpdateDeleteResultFields(): void {
		$dbModel = new db();
		$this->assertSame( 0, $dbModel->matched );
		$this->assertSame( 0, $dbModel->modified );
		$this->assertSame( 0, $dbModel->deleted );
		$this->assertSame( '', $dbModel->upsertedId );
		$this->assertSame( [], $dbModel->embeddedUpsertedIds );
	}

	public function testDbModelSetPopulatesFromUpdateDeleteResult(): void {
		$dbModel = new db();
		$result = new updateDeleteResult();

		$dbModel->set( $result );

		$this->assertSame( 0, $dbModel->matched );
		$this->assertSame( '', $dbModel->upsertedId );
	}

	public function testUiDefaultsAreAllFalseOrEmpty(): void {
		$u = new ui();
		$this->assertFalse( $u->loading );
		$this->assertFalse( $u->loadingDialog );
		$this->assertFalse( $u->adding );
		$this->assertFalse( $u->addingDialog );
		$this->assertFalse( $u->saving );
		$this->assertFalse( $u->savingDialog );
		$this->assertFalse( $u->editing );
		$this->assertFalse( $u->editingDialog );
		$this->assertFalse( $u->removing );
		$this->assertFalse( $u->removingDialog );
		$this->assertFalse( $u->error );
		$this->assertSame( '', $u->errorCode );
		$this->assertSame( '', $u->errorMessage );
		$this->assertFalse( $u->success );
		$this->assertSame( '', $u->successMessage );
	}

	public function testUiFieldDefaults(): void {
		$f = new uiField();
		$this->assertSame( '', $f->label );
		$this->assertFalse( $f->error );
		$this->assertSame( [], $f->errorMessages );
		$this->assertFalse( $f->success );
		$this->assertSame( [], $f->successMessages );
		$this->assertSame( '', $f->hints );
		$this->assertSame( '', $f->state );
		$this->assertFalse( $f->required );
		$this->assertTrue( $f->visible );
		$this->assertFalse( $f->valueIsVisibilityGroup );
		$this->assertSame( [], $f->visibilityGroups );
		$this->assertFalse( $f->validating );
	}

}
