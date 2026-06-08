<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\authUser;

#[CoversClass(authUser::class)]
final class AuthUserTest extends TestCase {

	protected function setUp(): void {
		$this->resetSingleton();
	}

	protected function tearDown(): void {
		$this->resetSingleton();
	}

	public function testGetInstanceReturnsSingleton(): void {
		$a = authUser::getInstance();
		$b = authUser::getInstance();
		$this->assertSame( $a, $b );
	}

	public function testDefaultsAreEmptyStringsAndEmptyArray(): void {
		$user = authUser::getInstance();
		$this->assertSame( '', $user->userId );
		$this->assertSame( '', $user->externalId );
		$this->assertSame( '', $user->externalProvider );
		$this->assertSame( '', $user->name );
		$this->assertSame( '', $user->username );
		$this->assertSame( '', $user->email );
		$this->assertSame( [], $user->roles );
	}

	public function testSetFromJwtTokenAssignsAllFields(): void {
		$user = authUser::getInstance();
		$user->setFromJwtToken(
			[
				'userId' => 'u-1',
				'username' => 'alice',
				'externalId' => 'ext-99',
				'externalProvider' => 'Google',
				'name' => 'Alice',
				'email' => 'alice@example.com',
			],
			[ 'User.Read', 'Widget.Write' ]
		);

		$this->assertSame( 'u-1', $user->userId );
		$this->assertSame( 'alice', $user->username );
		$this->assertSame( 'ext-99', $user->externalId );
		$this->assertSame( 'Google', $user->externalProvider );
		$this->assertSame( 'Alice', $user->name );
		$this->assertSame( 'alice@example.com', $user->email );
		$this->assertSame( [ 'User.Read', 'Widget.Write' ], $user->roles );
	}

	public function testSetFromJwtTokenWithMissingFieldsDefaultsToEmpty(): void {
		$user = authUser::getInstance();
		$user->setFromJwtToken( [], [] );
		$this->assertSame( '', $user->userId );
	}

	public function testSetFromJwtTokenReturnsSameSingleton(): void {
		$user = authUser::getInstance();
		$returned = $user->setFromJwtToken( [], [] );
		$this->assertSame( $user, $returned );
	}

	public function testToJwtDataMirrorsAllFields(): void {
		$user = authUser::getInstance();
		$user->userId = 'u-1';
		$user->name = 'Alice';
		$user->roles = [ 'User.Read' ];

		$jwt = $user->toJwtData();
		$this->assertSame( 'u-1', $jwt[ 'userId' ] );
		$this->assertSame( 'Alice', $jwt[ 'name' ] );
		$this->assertSame( [ 'User.Read' ], $jwt[ 'roles' ] );
		$this->assertArrayHasKey( 'username', $jwt );
		$this->assertArrayHasKey( 'externalId', $jwt );
		$this->assertArrayHasKey( 'externalProvider', $jwt );
		$this->assertArrayHasKey( 'email', $jwt );
	}

	public function testHasRoleReflectsCurrentRoles(): void {
		$user = authUser::getInstance();
		$user->roles = [ 'A', 'B' ];

		$this->assertTrue( $user->hasRole( 'A' ) );
		$this->assertTrue( $user->hasRole( 'B' ) );
		$this->assertFalse( $user->hasRole( 'C' ) );
	}

	public function testSetFromUserPopulatesFromInterface(): void {
		$source = new class implements \gcgov\framework\interfaces\auth\user {
			public function getId(): string|int|\MongoDB\BSON\ObjectId { return 'u-1'; }
			public function getName(): string { return 'Bob'; }
			public function getUsername(): string { return 'bob'; }
			public function getPassword(): string { return ''; }
			public function getOauthId(): string { return 'oauth-1'; }
			public function getOauthProvider(): string { return 'Provider'; }
			public function getEmail(): string { return 'bob@example.com'; }
			public function getRoles(): array { return [ 'Reader' ]; }
			public function getActive(): bool { return true; }
			public static function getFromOauth( string $email, string $externalId, string $externalProvider, ?string $firstName = '', ?string $lastName = '', bool $addIfNotExisting = false, array $rolesForNewUser=[] ): self { throw new \BadMethodCallException(); }
			public static function verifyUsernamePassword( string $username, string $password ): self { throw new \BadMethodCallException(); }
			public static function getOneByExternalId( string $externalId ): self { throw new \BadMethodCallException(); }
			public static function getOneByEmail( string $email ): self { throw new \BadMethodCallException(); }
			public static function getOne( \MongoDB\BSON\ObjectId|string|int $_id ): self { throw new \BadMethodCallException(); }
			public static function save( object &$object ): mixed { return null; }
		};

		$auth = authUser::getInstance();
		$returned = $auth->setFromUser( $source );

		$this->assertSame( $auth, $returned );
		$this->assertSame( 'u-1', $auth->userId );
		$this->assertSame( 'Bob', $auth->name );
		$this->assertSame( 'bob', $auth->username );
		$this->assertSame( 'oauth-1', $auth->externalId );
		$this->assertSame( 'Provider', $auth->externalProvider );
		$this->assertSame( 'bob@example.com', $auth->email );
		$this->assertSame( [ 'Reader' ], $auth->roles );
	}

	public function testSleepReturnsEmptyArray(): void {
		$this->assertSame( [], authUser::getInstance()->__sleep() );
	}

	public function testConstructorIsPrivate(): void {
		$reflection = new \ReflectionMethod( authUser::class, '__construct' );
		$this->assertTrue( $reflection->isPrivate() );
	}

	public function testCloneIsFinal(): void {
		$this->assertTrue( ( new \ReflectionMethod( authUser::class, '__clone' ) )->isFinal() );
	}

	private function resetSingleton(): void {
		$prop = new \ReflectionProperty( authUser::class, 'instance' );
		if ( $prop->isInitialized() ) {
			$prop->setValue( null, ( new \ReflectionClass( authUser::class ) )->newInstanceWithoutConstructor() );
		}
	}

}
