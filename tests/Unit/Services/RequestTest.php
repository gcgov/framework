<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\request;

#[CoversClass(request::class)]
final class RequestTest extends TestCase {

	protected function setUp(): void {
		$_POST = [];
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

}
