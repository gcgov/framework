<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use gcgov\framework\models\authUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roles are documented string[] but reach authUser from two untyped sources: the token's
 * `scope` claim, which is whatever JSON the token carried, and the user model's roles
 * field, which is whatever the collection holds (BSON deserialization passes scalars
 * through untouched).
 *
 * A single non-string truthy element used to satisfy a loose in_array() against EVERY
 * required role — 'User.Write' == true is true — so one such element authorized every gated
 * route in the application.
 */
#[CoversClass(authUser::class)]
final class AuthUserRolesTest extends TestCase {

	protected function tearDown(): void {
		authUser::getInstance()->setFromJwtToken( [], [] );
	}


	public function testStringRolesSurviveUnchanged(): void {
		$authUser = authUser::getInstance()->setFromJwtToken( [], [ 'User.Read', 'User.Write' ] );

		$this->assertSame( [ 'User.Read', 'User.Write' ], $authUser->roles );
		$this->assertTrue( $authUser->hasRole( 'User.Read' ) );
		$this->assertFalse( $authUser->hasRole( 'User.Delete' ) );
	}


	#[DataProvider('nonStringScopeClaims')]
	public function testNonStringScopeElementsGrantNothing( array $scope, string $description ): void {
		$authUser = authUser::getInstance()->setFromJwtToken( [], $scope );

		$this->assertFalse( $authUser->hasRole( 'User.Write' ), $description );
		$this->assertFalse( $authUser->hasRole( 'Anything.At.All' ), $description );
		foreach( $authUser->roles as $role ) {
			$this->assertIsString( $role, 'roles must be string[] as documented' );
		}
	}


	/** @return array<string, array{list<mixed>, string}> */
	public static function nonStringScopeClaims(): array {
		return [
			'boolean true'  => [ [ true ], 'true == "User.Write" under a loose comparison' ],
			'integer one'   => [ [ 1 ], 'a truthy int must not stand in for a role name' ],
			'float'         => [ [ 1.0 ], 'a truthy float must not stand in for a role name' ],
			'nested array'  => [ [ [ 'User.Write' ] ], 'a nested array is not a role' ],
			'object'        => [ [ new \stdClass() ], 'an object is not a role' ],
			'null'          => [ [ null ], 'null is not a role' ],
		];
	}


	public function testMixedScopeKeepsOnlyTheRealRoles(): void {
		$authUser = authUser::getInstance()->setFromJwtToken( [], [ 'User.Read', true, 'User.Write', 1 ] );

		$this->assertSame( [ 'User.Read', 'User.Write' ], $authUser->roles );
		$this->assertTrue( $authUser->hasRole( 'User.Read' ) );
		$this->assertTrue( $authUser->hasRole( 'User.Write' ) );
		$this->assertFalse( $authUser->hasRole( 'User.Delete' ) );
	}


	/** The same narrowing has to apply to roles arriving from the user model, not only the token. */
	public function testRolesFromTheUserModelAreNarrowedToo(): void {
		require_once __DIR__ . '/../../Stubs/FakeUserModel.php';

		$user        = new \app\models\user();
		$user->_id   = '507f1f77bcf86cd799439011';
		$user->name  = 'Test User';
		$user->email = 'test@example.gov';
		// A roles array written out of band — a migration, an admin tool, a direct DB write.
		$user->roles = [ 'User.Read', true ];

		$authUser = authUser::getInstance()->setFromUser( $user );

		$this->assertSame( [ 'User.Read' ], $authUser->roles );
		$this->assertFalse( $authUser->hasRole( 'User.Write' ) );
	}

}
