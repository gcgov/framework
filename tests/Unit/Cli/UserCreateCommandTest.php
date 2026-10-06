<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\userCreateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `gf user:create` exists because an application with services.auth enabled has no other way
 * to get its first user: blockNewUsers defaults to true, every /user route needs a caller who
 * already holds User.Write, and a hand written mongosh document has no usable password
 * because the model hashes on write.
 *
 * The command's whole judgement lives in applyTo() and parseRoles(), which are pure — driven
 * here directly rather than through CommandTester, which would need a live database to reach
 * them.
 */
#[CoversClass(userCreateCommand::class)]
final class UserCreateCommandTest extends TestCase {

	public function testUsernameDefaultsToTheEmail(): void {
		$user = $this->newUser();

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test' ] );

		self::assertSame( 'dev@example.test', $user->email );
		self::assertSame( 'dev@example.test', $user->username, 'verifyUsernamePassword() matches on username first, so a user created without one could never sign in' );
	}


	public function testAnExplicitUsernameWins(): void {
		$user = $this->newUser();

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test', 'username' => 'dev' ] );

		self::assertSame( 'dev', $user->username );
	}


	/**
	 * The model hashes in _beforeBsonSerialize(). Hashing here too would store a hash of a
	 * hash, and no password would ever verify — the failure would look like a wrong password.
	 */
	public function testThePasswordIsStoredAsPlaintextForTheModelToHash(): void {
		$user = $this->newUser();

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test', 'password' => 'correct horse' ] );

		self::assertSame( 'correct horse', $user->password );
	}


	/**
	 * An omitted password on a --force update must leave the stored one alone: the model
	 * unsets an empty password rather than writing it, so `--force --roles=…` is a safe way
	 * to add a role.
	 */
	public function testAnOmittedPasswordLeavesTheExistingOneAlone(): void {
		$user           = $this->newUser();
		$user->password = 'already-hashed';

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test', 'password' => '' ] );

		self::assertSame( 'already-hashed', $user->password );
	}


	public function testOmittedRolesAreLeftAloneButAnEmptyListClearsThem(): void {
		$user        = $this->newUser();
		$user->roles = [ 'User.Read' ];

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test', 'roles' => null ] );
		self::assertSame( [ 'User.Read' ], $user->roles, 'a --roles that was never passed must not silently strip a user\'s roles' );

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test', 'roles' => [] ] );
		self::assertSame( [], $user->roles );
	}


	public function testNameIsOnlyWrittenWhenSupplied(): void {
		$user       = $this->newUser();
		$user->name = 'Existing Name';

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test' ] );

		self::assertSame( 'Existing Name', $user->name );
	}


	/**
	 * factory::save() reads the typed $_id unconditionally to build its update filter, so an
	 * uninitialized property is a fatal Error rather than an insert — the same fault that
	 * broke POST /user/new.
	 */
	public function testAnIdIsAssignedWhenTheModelHasNotSetOne(): void {
		$user = $this->newUser();

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test' ] );

		self::assertTrue( isset( $user->_id ) );
	}


	public function testAnExistingIdIsKept(): void {
		$user      = $this->newUser();
		$user->_id = 'keep-me';

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test' ] );

		self::assertSame( 'keep-me', $user->_id );
	}


	public function testTheUserIsActivated(): void {
		$user         = $this->newUser();
		$user->active = false;

		userCreateCommand::applyTo( $user, [ 'email' => 'dev@example.test' ] );

		self::assertTrue( $user->active );
	}


	public function testRolesAreSplitTrimmedAndDeduplicated(): void {
		self::assertSame( [ 'User.Read', 'User.Write' ], userCreateCommand::parseRoles( ' User.Read , User.Write ' ) );
		self::assertSame( [ 'User.Read' ], userCreateCommand::parseRoles( 'User.Read,User.Read' ) );
	}


	/** A trailing comma is a typo, not a role named "". */
	public function testEmptyRoleEntriesAreDropped(): void {
		self::assertSame( [ 'User.Read' ], userCreateCommand::parseRoles( 'User.Read,,' ) );
		self::assertSame( [], userCreateCommand::parseRoles( '' ) );
	}


	/**
	 * Stands in for the user model's public properties (the userTrait ones), which is all
	 * applyTo() touches. Untyped so a test can leave $_id unset the way a typed ObjectId is.
	 */
	private function newUser(): object {
		return new class {
			public $_id;
			public string $email    = '';
			public string $username = '';
			public string $name     = '';
			public string $password = '';
			/** @var string[] */
			public array $roles  = [];
			public bool  $active = true;
		};
	}

}
