<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Microsoft;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\microsoft\components\envelope;
use gcgov\framework\services\microsoft\components\tokenInfomation;
use gcgov\framework\services\microsoft\components\upload;

#[CoversClass(envelope::class)]
#[CoversClass(tokenInfomation::class)]
#[CoversClass(upload::class)]
final class ComponentsTest extends TestCase {

	public function testEnvelopeDefaultsAreSuccessStatusWithEmptyMessage(): void {
		$env = new envelope();
		$this->assertFalse( $env->error );
		$this->assertSame( '', $env->message );
		$this->assertSame( 200, $env->status );
		$this->assertSame( [], $env->data );
	}

	public function testEnvelopeConstructorAssignsAllArguments(): void {
		$env = new envelope( 500, true, 'oops', [ 'k' => 'v' ] );
		$this->assertSame( 500, $env->status );
		$this->assertTrue( $env->error );
		$this->assertSame( 'oops', $env->message );
		$this->assertSame( [ 'k' => 'v' ], $env->data );
	}

	public function testTokenInformationDefaultsAreEmptyStrings(): void {
		$info = new tokenInfomation();
		$this->assertSame( '', $info->aud );
		$this->assertSame( '', $info->iss );
		$this->assertSame( '', $info->oid );
		$this->assertSame( '', $info->sub );
		$this->assertSame( '', $info->appId );
		$this->assertSame( '', $info->name );
		$this->assertSame( '', $info->familyName );
		$this->assertSame( '', $info->givenName );
		$this->assertSame( '', $info->ip );
		$this->assertSame( '', $info->scope );
		$this->assertSame( '', $info->email );
		$this->assertSame( '', $info->preferred_username );
		$this->assertSame( '', $info->upn );
		$this->assertSame( '', $info->uniqueName );
	}

	public function testUploadDefaultsAreEmptyArrays(): void {
		$u = new upload();
		$this->assertSame( [], $u->files );
		$this->assertSame( [], $u->errors );
	}

	public function testUploadMergeCombinesFilesAndErrors(): void {
		$a = new upload();
		$a->files = [ 'file1' ];
		$a->errors = [ new envelope( 500, true, 'err-a' ) ];

		$b = new upload();
		$b->files = [ 'file2', 'file3' ];
		$b->errors = [ new envelope( 500, true, 'err-b' ) ];

		$a->merge( $b );

		$this->assertSame( [ 'file1', 'file2', 'file3' ], $a->files );
		$this->assertCount( 2, $a->errors );
	}

}
