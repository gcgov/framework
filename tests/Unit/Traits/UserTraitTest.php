<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Traits;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use gcgov\framework\traits\userTrait;

#[CoversTrait(userTrait::class)]
final class UserTraitTest extends TestCase {

	public function testGettersReadPropertiesDirectly(): void {
		$consumer = new class {
			use userTrait;
		};

		$consumer->name = 'Alice';
		$consumer->username = 'alice';
		$consumer->password = 'secret';
		$consumer->oauthId = 'oauth-1';
		$consumer->oauthProvider = 'Google';
		$consumer->email = 'alice@example.com';
		$consumer->roles = [ 'Reader' ];
		$consumer->active = true;
		$consumer->mfaRequired = true;
		$consumer->mfaConfigured = false;

		$this->assertSame( 'Alice', $consumer->getName() );
		$this->assertSame( 'alice', $consumer->getUsername() );
		$this->assertSame( 'secret', $consumer->getPassword() );
		$this->assertSame( 'oauth-1', $consumer->getOauthId() );
		$this->assertSame( 'Google', $consumer->getOauthProvider() );
		$this->assertSame( 'alice@example.com', $consumer->getEmail() );
		$this->assertSame( [ 'Reader' ], $consumer->getRoles() );
		$this->assertTrue( $consumer->getActive() );
		$this->assertTrue( $consumer->mfaRequired );
		$this->assertFalse( $consumer->mfaConfigured );
	}

	public function testDefaultPropertyValues(): void {
		$consumer = new class {
			use userTrait;
		};

		$this->assertSame( '', $consumer->name );
		$this->assertSame( '', $consumer->username );
		$this->assertSame( '', $consumer->oauthId );
		$this->assertSame( '', $consumer->oauthProvider );
		$this->assertSame( '', $consumer->email );
		$this->assertSame( '', $consumer->password );
		$this->assertSame( [], $consumer->roles );
		$this->assertTrue( $consumer->active );
		$this->assertFalse( $consumer->mfaRequired );
		$this->assertFalse( $consumer->mfaConfigured );
	}

}
