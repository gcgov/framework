<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\request;

#[CoversClass(request::class)]
final class RequestTest extends TestCase {

	/** @var array<string, mixed> */
	private array $serverBackup = [];


	protected function setUp(): void {
		$_POST              = [];
		$_GET               = [];
		$_REQUEST           = [];
		$this->serverBackup = $_SERVER;
	}


	protected function tearDown(): void {
		$_SERVER  = $this->serverBackup;
		$_GET     = [];
		$_REQUEST = [];
	}

	public function testGetAuthUserReturnsFrameworkAuthUserWhenNoAppSpecificClass(): void {
		// \app\models\authUser does not exist in this test repo, so the
		// framework's authUser singleton is returned.
		$user = request::getAuthUser();
		$this->assertInstanceOf( \gcgov\framework\models\authUser::class, $user );
	}

	public function testGetUserClassFqdnReturnsFrameworkMongoUserByDefault(): void {
		$class = request::getUserClassFqdn();
		$this->assertSame(
			\gcgov\framework\services\mongodb\models\auth\user::class,
			$class
		);
	}

	public function testGetPostDataReturnsPostArrayWhenPopulated(): void {
		$_POST = [ 'name' => 'Alice', 'role' => 'Admin' ];
		$this->assertSame( $_POST, request::getPostData() );
	}

	public function testGetPostDataAttemptsJsonBodyWhenPostEmpty(): void {
		// php://input is normally empty in CLI; json_decode of empty returns
		// null, which the method coerces to an empty array.
		$_POST = [];
		$result = request::getPostData();
		$this->assertIsArray( $result );
	}


	public function testCliRouteQueryStringPopulatesGet(): void {
		$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
		$_SERVER[ 'REQUEST_URI' ]    = '/cli/import?limit=5&name=Jo%20Smith&ids[]=1&ids[]=2';

		request::populateCliQueryParameters();

		$this->assertSame( [ 'limit' => '5', 'name' => 'Jo Smith', 'ids' => [ '1', '2' ] ], $_GET );
		$this->assertSame( 'limit=5&name=Jo%20Smith&ids[]=1&ids[]=2', $_SERVER[ 'QUERY_STRING' ] );
		$this->assertSame( '5', $_REQUEST[ 'limit' ] );
	}


	public function testCliRouteFragmentIsNotPartOfQueryString(): void {
		$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
		$_SERVER[ 'REQUEST_URI' ]    = '/cli/import?download#top';

		request::populateCliQueryParameters();

		$this->assertSame( [ 'download' => '' ], $_GET );
		$this->assertSame( 'download', $_SERVER[ 'QUERY_STRING' ] );
	}


	public function testCliRouteWithoutQueryStringLeavesGetUntouched(): void {
		$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
		$_SERVER[ 'REQUEST_URI' ]    = '/cli/import';
		unset( $_SERVER[ 'QUERY_STRING' ] );
		$_GET = [ 'preset' => 'kept' ];

		request::populateCliQueryParameters();

		$this->assertSame( [ 'preset' => 'kept' ], $_GET );
		$this->assertArrayNotHasKey( 'QUERY_STRING', $_SERVER );
	}


	public function testHttpRequestIsNeverRewritten(): void {
		// Over HTTP the SAPI already parsed the query string; the framework must not
		// replace what the web server gave it.
		$_SERVER[ 'REQUEST_METHOD' ] = 'GET';
		$_SERVER[ 'REQUEST_URI' ]    = '/widget?limit=5';
		$_GET                        = [ 'limit' => 'from-sapi' ];

		request::populateCliQueryParameters();

		$this->assertSame( [ 'limit' => 'from-sapi' ], $_GET );
	}


	public function testExtractQueryString(): void {
		$this->assertNull( request::extractQueryString( '/cli/import' ) );
		$this->assertSame( '', request::extractQueryString( '/cli/import?' ) );
		$this->assertSame( 'a=1&b=2', request::extractQueryString( '/cli/import?a=1&b=2' ) );
		$this->assertSame( 'a=1', request::extractQueryString( '/cli/import?a=1#section' ) );
		$this->assertSame( 'next=/x?y=1', request::extractQueryString( '/cli/import?next=/x?y=1' ) );
	}

}
