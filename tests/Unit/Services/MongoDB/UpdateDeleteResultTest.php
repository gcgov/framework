<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\updateDeleteResult;

#[CoversClass(updateDeleteResult::class)]
final class UpdateDeleteResultTest extends TestCase {

	public function testNullResultProducesEmptyCounters(): void {
		$r = new updateDeleteResult();
		$this->assertFalse( $r->isAcknowledged() );
		$this->assertSame( 0, $r->getDeletedCount() );
		$this->assertSame( 0, $r->getModifiedCount() );
		$this->assertSame( 0, $r->getMatchedCount() );
		$this->assertSame( 0, $r->getUpsertedCount() );
		$this->assertNull( $r->getUpsertedId() );
		$this->assertSame( 0, $r->getEmbeddedDeletedCount() );
		$this->assertSame( 0, $r->getEmbeddedModifiedCount() );
		$this->assertSame( 0, $r->getEmbeddedMatchedCount() );
		$this->assertSame( 0, $r->getEmbeddedUpsertedCount() );
		$this->assertSame( [], $r->getEmbeddedUpsertedIds() );
	}

	public function testJsonSerializeShape(): void {
		$json = ( new updateDeleteResult() )->jsonSerialize();
		$expectedKeys = [
			'acknowledged', 'deletedCount', 'modifiedCount', 'matchedCount',
			'upsertedCount', 'embeddedDeletedCount', 'embeddedModifiedCount',
			'embeddedMatchedCount', 'embeddedUpsertedCount',
		];
		foreach ( $expectedKeys as $key ) {
			$this->assertArrayHasKey( $key, $json );
		}
		$this->assertFalse( $json[ 'acknowledged' ] );
	}

	public function testJsonSerializeIsJsonable(): void {
		$json = json_encode( ( new updateDeleteResult() ) );
		$this->assertJson( $json );
	}

	public function testImplementsJsonSerializable(): void {
		$this->assertInstanceOf( \JsonSerializable::class, new updateDeleteResult() );
	}

}
