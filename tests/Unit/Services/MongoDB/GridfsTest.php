<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\gridfs;

#[CoversClass(gridfs::class)]
final class GridfsTest extends TestCase {

	public function testDefaultsAreNullOrEmpty(): void {
		$file = new gridfs();
		$this->assertNull( $file->_id );
		$this->assertSame( '', $file->filename );
		$this->assertSame( '', $file->contentType );
		$this->assertSame( '', $file->base64EncodedContent );
		$this->assertNull( $file->uploadDate );
	}

	public function testCollectionNameDerivedFromShortClassNameByDefault(): void {
		$this->assertSame( 'gridfs', gridfs::_getCollectionName() );
	}

	public function testCollectionNameUsesUnderscoreConstantWhenSubclassDeclaresIt(): void {
		$this->assertSame( 'attachments', GridfsTestAttachments::_getCollectionName() );
	}

	public function testFieldsAreMutable(): void {
		$file = new gridfs();
		$file->filename = 'photo.jpg';
		$file->contentType = 'image/jpeg';
		$file->base64EncodedContent = 'aGVsbG8=';
		$file->uploadDate = new \DateTimeImmutable();

		$this->assertSame( 'photo.jpg', $file->filename );
		$this->assertSame( 'image/jpeg', $file->contentType );
		$this->assertSame( 'aGVsbG8=', $file->base64EncodedContent );
		$this->assertInstanceOf( \DateTimeImmutable::class, $file->uploadDate );
	}

	public function testExtendsJsonDeserialize(): void {
		$this->assertTrue(
			is_subclass_of( gridfs::class, \andrewsauder\jsonDeserialize\jsonDeserialize::class )
		);
	}

}

class GridfsTestAttachments extends gridfs {
	const _COLLECTION = 'attachments';
}
