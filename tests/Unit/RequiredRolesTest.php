<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\exceptions\routeException;
use gcgov\framework\models\authUser;
use gcgov\framework\models\routeHandler;
use gcgov\framework\router;
use gcgov\framework\tests\Support\capturesFrameworkLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * requiredRoles is declared on the framework's own route model but used to be read only by
 * the guard inside the OPTIONAL auth service, so two supported configurations declared roles
 * that nothing checked: an application providing its own authentication with no services.auth
 * block, and any route where skipsServiceAuthentication skipped the service guards. Both
 * looked protected in the route table and in review.
 *
 * Enforcement now lives in router::assertRequiredRoles(), which runs after the whole guard
 * chain and therefore holds however the caller was authenticated.
 */
#[CoversClass(router::class)]
final class RequiredRolesTest extends TestCase {

	use capturesFrameworkLog;

	protected function tearDown(): void {
		// the authenticated user is a singleton; leave it as the next test expects to find it
		authUser::getInstance()->setFromJwtToken( [], [] );
	}


	/** A route that declares no roles must not start requiring authentication. */
	public function testRouteWithoutRolesPassesEvenWithNoUser(): void {
		$this->expectNotToPerformAssertions();

		$this->assertRoles( $this->handler( [] ) );
	}


	/**
	 * The regression the change exists for: roles declared, nobody authenticated. This
	 * previously sailed through — the only role check in the codebase was never reached.
	 */
	public function testRolesDeclaredWithNoUserEstablishedIs401(): void {
		$log = $this->captureLog( 'Framework Lifecycle' );

		// try/catch rather than expectException: the refusal and the diagnostic that goes
		// with it are one behaviour. A 401 whose cause is unlogged sends an application
		// developer hunting through the guard chain for a route that is simply unguarded.
		try {
			$this->assertRoles( $this->handler( [ 'User.Read' ] ) );
			$this->fail( 'a role-gated route with no authenticated user must be refused' );
		}
		catch( routeException $e ) {
			$this->assertSame( 401, $e->getCode() );
		}

		$this->assertTrue( $log->hasWarningThatContains( 'User.Read' ), 'the log must name the role that was required' );
		$this->assertTrue( $log->hasWarningThatContains( 'services.auth' ), 'and what to do about it' );
	}


	public function testUserHoldingEveryRequiredRolePasses(): void {
		$this->authenticate( [ 'User.Read', 'User.Write' ] );

		$this->expectNotToPerformAssertions();

		$this->assertRoles( $this->handler( [ 'User.Read', 'User.Write' ] ) );
	}


	public function testUserMissingARoleIs403(): void {
		$this->authenticate( [ 'User.Read' ] );

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'User does not have the permission "User.Write" required to access this content' );

		$this->assertRoles( $this->handler( [ 'User.Read', 'User.Write' ] ) );
	}


	/** Every required role must be held — a subset is not enough. */
	public function testHoldingASubsetIs403(): void {
		$this->authenticate( [ 'User.Read', 'Widget.Read' ] );

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 403 );

		$this->assertRoles( $this->handler( [ 'User.Read', 'User.Write', 'Widget.Read' ] ) );
	}


	public function testUnrelatedRolesDoNotSatisfyTheRequirement(): void {
		$this->authenticate( [ 'Widget.Read', 'Widget.Write' ] );

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 403 );

		$this->assertRoles( $this->handler( [ 'User.Read' ] ) );
	}


	/**
	 * The narrowing in authUser::normalizeRoles() has to hold through this call path too: a
	 * non-string truthy element in the token's scope claim satisfied a loose comparison
	 * against every required role.
	 */
	#[DataProvider('nonStringScopes')]
	public function testNonStringScopeElementsDoNotSatisfyARole( array $scope ): void {
		$this->authenticate( $scope );

		$this->expectException( routeException::class );
		$this->expectExceptionCode( 403 );

		$this->assertRoles( $this->handler( [ 'User.Write' ] ) );
	}


	/** @return array<string, array{list<mixed>}> */
	public static function nonStringScopes(): array {
		return [
			'boolean true' => [ [ true ] ],
			'integer one'  => [ [ 1 ] ],
			'float'        => [ [ 1.0 ] ],
		];
	}


	/** Establish a user the way the auth guard does, so roles have something to check against. */
	private function authenticate( array $roles ): void {
		authUser::getInstance()->setFromJwtToken( [ 'userId' => '507f1f77bcf86cd799439011' ], $roles );
	}


	private function handler( array $requiredRoles ): routeHandler {
		return new routeHandler( '\app\controllers\widget', 'getOne', true, $requiredRoles );
	}


	/** @throws routeException */
	private function assertRoles( routeHandler $routeHandler ): void {
		( new \ReflectionMethod( router::class, 'assertRequiredRoles' ) )->invoke( null, $routeHandler );
	}

}
