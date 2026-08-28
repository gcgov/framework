<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\UserCrud\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\userCrud\controllers\user;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerPagedDataResponse;
use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelException;
use app\models\user as FakeUser;

#[CoversClass(user::class)]
/**
 * The controller resolves its model through request::getUserClassFqdn(), which returns
 * \app\models\user when that class exists. Defining it is therefore global state: the
 * framework's own RequestTest asserts the opposite — that with no application user model
 * the Mongo default is returned — and one process cannot hold both.
 *
 * So these run isolated, and the stub is required in setUpBeforeClass (which runs in the
 * child) rather than at file scope (which would define it in the parent too).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UserControllerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../../../Stubs/FakeUserModel.php';
	}


	protected function setUp(): void {
		FakeUser::reset();
		unset( $_GET[ 'limit' ], $_GET[ 'page' ] );
	}

	public function testControllerImplementsFrameworkInterface(): void {
		$this->assertContains(
			\gcgov\framework\interfaces\controller::class,
			class_implements( user::class ) ?: []
		);
	}

	public function testGetAllReturnsPagedDataResponse(): void {
		$response = ( new user() )->getAll();
		$this->assertInstanceOf( controllerPagedDataResponse::class, $response );
	}

	public function testGetAllReturnsRecordsFromUserModel(): void {
		$record = new FakeUser();
		$record->_id = 'u1';
		$record->name = 'Alice';
		FakeUser::$records[ 'u1' ] = $record;

		$response = ( new user() )->getAll();
		$this->assertInstanceOf( controllerPagedDataResponse::class, $response );
	}

	public function testGetAllWrapsModelExceptionInControllerException(): void {
		FakeUser::$nextException = new modelException( 'boom', 500 );

		$this->expectException( controllerException::class );
		$this->expectExceptionMessage( 'boom' );
		( new user() )->getAll();
	}

	public function testGetOneReturnsExistingUser(): void {
		$record = new FakeUser();
		$record->_id = 'u42';
		$record->name = 'Bob';
		FakeUser::$records[ 'u42' ] = $record;

		$response = ( new user() )->getOne( 'u42' );
		$this->assertInstanceOf( controllerDataResponse::class, $response );
	}

	public function testGetOneWithNewSentinelInstantiatesEmptyUser(): void {
		$response = ( new user() )->getOne( 'new' );
		$this->assertInstanceOf( controllerDataResponse::class, $response );
	}

	public function testGetOneWrapsModelExceptionInControllerException(): void {
		$this->expectException( controllerException::class );
		( new user() )->getOne( 'does-not-exist' );
	}

	public function testSaveDeserializesPayloadAndPersists(): void {
		$payload = json_encode( [ '_id' => 'u99', 'name' => 'Carol' ] );
		$this->withPhpInput( $payload, function() {
			$response = ( new user() )->save( 'u99' );
			$this->assertInstanceOf( controllerDataResponse::class, $response );
			$this->assertArrayHasKey( 'u99', FakeUser::$records );
			$this->assertSame( 'Carol', FakeUser::$records[ 'u99' ]->name );
		} );
	}

	/**
	 * The URL decides which document is written. This method used to ignore its $_id
	 * entirely, so a caller holding User.Write could POST to their own /user/{_id} with a
	 * body naming any other account and overwrite it — roles included.
	 */
	public function testSaveRejectsABodyTargetingADifferentUser(): void {
		$victim = new FakeUser();
		$victim->_id = 'victim';
		$victim->name = 'Victim';
		FakeUser::$records[ 'victim' ] = $victim;

		$this->withPhpInput( json_encode( [ '_id' => 'victim', 'name' => 'Owned' ] ), function() {
			try {
				( new user() )->save( 'attacker' );
				$this->fail( 'the body must not be able to retarget the write' );
			}
			catch( controllerException $e ) {
				$this->assertSame( 400, $e->getCode() );
			}
		} );

		$this->assertSame( 'Victim', FakeUser::$records[ 'victim' ]->name, 'the other account must be untouched' );
	}


	public function testSaveRejectsABodyWithNoId(): void {
		$this->withPhpInput( json_encode( [ 'name' => 'Nameless' ] ), function() {
			$this->expectException( controllerException::class );
			$this->expectExceptionCode( 400 );
			( new user() )->save( 'u1' );
		} );
	}


	/** POST /user/new must create, not overwrite whatever _id the body happens to carry. */
	public function testSaveToNewDoesNotOverwriteAnExistingAccount(): void {
		$victim = new FakeUser();
		$victim->_id = 'victim';
		$victim->name = 'Victim';
		FakeUser::$records[ 'victim' ] = $victim;

		$this->withPhpInput( json_encode( [ '_id' => 'victim', 'name' => 'Owned' ] ), function() {
			( new user() )->save( 'new' );
		} );

		$this->assertSame( 'Victim', FakeUser::$records[ 'victim' ]->name, 'the named account must be untouched' );
	}


	public function testSaveWrapsModelExceptionInControllerException(): void {
		FakeUser::$nextException = new modelException( 'validation failure', 422 );

		$this->withPhpInput( json_encode( [ '_id' => 'u1' ] ), function() {
			$this->expectException( controllerException::class );
			$this->expectExceptionCode( 422 );
			( new user() )->save( 'u1' );
		} );
	}

	public function testDeleteReturnsNoContentWhenSucceeded(): void {
		$record = new FakeUser();
		$record->_id = 'u88';
		FakeUser::$records[ 'u88' ] = $record;
		FakeUser::$deleteAffectedCount = 1;

		$response = ( new user() )->delete( 'u88' );
		$this->assertInstanceOf( controllerDataResponse::class, $response );
		$this->assertSame( 204, $response->getHttpStatus() );
	}

	public function testDeleteThrows404WhenNoRecordsAffected(): void {
		FakeUser::$deleteAffectedCount = 0;

		$this->expectException( controllerException::class );
		$this->expectExceptionCode( 404 );
		( new user() )->delete( 'missing' );
	}

	public function testDeleteWrapsModelExceptionInControllerException(): void {
		FakeUser::$nextException = new modelException( 'db error', 500 );

		$this->expectException( controllerException::class );
		$this->expectExceptionMessage( 'db error' );
		( new user() )->delete( 'u1' );
	}

	public function testLifecycleHooksReturnVoid(): void {
		user::_before();
		user::_after();

		$reflection = new \ReflectionClass( user::class );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_before' )->getReturnType() );
		$this->assertSame( 'void', (string) $reflection->getMethod( '_after' )->getReturnType() );
	}

	public function testConstructorAcceptsNoArguments(): void {
		$reflection = new \ReflectionClass( user::class );
		$constructor = $reflection->getConstructor();
		$this->assertNotNull( $constructor );
		$this->assertSame( 0, $constructor->getNumberOfRequiredParameters() );
	}

	private function withPhpInput( string $body, callable $work ): void {
		// php://input is read-only, so we hijack via a stream wrapper.
		stream_wrapper_unregister( 'php' );
		stream_wrapper_register( 'php', PhpInputStreamWrapper::class );
		PhpInputStreamWrapper::$content = $body;
		try {
			$work();
		}
		finally {
			stream_wrapper_restore( 'php' );
		}
	}

}

final class PhpInputStreamWrapper {
	public static string $content = '';
	public $context;
	private int $position = 0;

	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		$this->position = 0;
		return true;
	}

	public function stream_read( int $count ): string {
		$ret = substr( self::$content, $this->position, $count );
		$this->position += strlen( $ret );
		return $ret;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen( self::$content );
	}

	public function stream_stat(): array {
		return [ 'size' => strlen( self::$content ) ];
	}

	public function stream_close(): void {}

	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		switch ( $whence ) {
			case SEEK_SET: $this->position = $offset; break;
			case SEEK_CUR: $this->position += $offset; break;
			case SEEK_END: $this->position = strlen( self::$content ) + $offset; break;
		}
		return true;
	}

	public function stream_tell(): int {
		return $this->position;
	}

	public function url_stat( string $path, int $flags ): array|false {
		return [ 'size' => strlen( self::$content ) ];
	}
}
