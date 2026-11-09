<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\JwtAuth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\jwtAuth\models\userRefreshToken;
use MongoDB\BSON\ObjectId;

#[CoversClass(userRefreshToken::class)]
final class UserRefreshTokenTest extends TestCase {

	protected function setUp(): void {
		unset( $_SERVER[ 'HTTP_USER_AGENT' ], $_SERVER[ 'REMOTE_ADDR' ] );
	}

	/**
	 * revoke() deletes one row, and only the owner's: a session id alone must never revoke
	 * another user's refresh token, and nothing here may widen into deleting all of them.
	 */
	public function testRevokeDeletesOneRowScopedToItsOwner(): void {
		$method = new \ReflectionMethod( userRefreshToken::class, 'revoke' );
		$this->assertTrue( $method->isStatic() );
		$this->assertSame( 'bool', (string)$method->getReturnType() );

		$source = implode( '', array_slice(
			(array)file( (string)$method->getFileName() ),
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1
		) );
		$this->assertStringContainsString( "'_id'    => \$_id", $source );
		$this->assertStringContainsString( "'userId' => \$userId", $source );
		$this->assertStringContainsString( '->deleteOne(', $source );
		$this->assertStringNotContainsString( 'deleteMany', $source );
	}

	public function testCollectionConstantsAreDefined(): void {
		$this->assertSame( 'userRefreshToken', userRefreshToken::_COLLECTION );
		$this->assertSame( 'user refresh token', userRefreshToken::_HUMAN );
		$this->assertSame( 'user refresh tokens', userRefreshToken::_HUMAN_PLURAL );
	}

	public function testConstructorHashesProvidedToken(): void {
		$id = new ObjectId();
		$model = new userRefreshToken( $id, new \DateInterval( 'P30D' ), 'rawtoken' );

		$this->assertNotSame( 'rawtoken', $model->token );
		$this->assertTrue( password_verify( 'rawtoken', $model->token ) );
	}

	public function testConstructorAcceptsStringUserId(): void {
		$model = new userRefreshToken(
			'507f1f77bcf86cd799439011',
			new \DateInterval( 'P1D' ),
			'secret'
		);
		$this->assertInstanceOf( ObjectId::class, $model->userId );
		$this->assertSame( '507f1f77bcf86cd799439011', (string) $model->userId );
	}

	public function testConstructorDefaultsCreatorContextFromServerSuperglobal(): void {
		$_SERVER[ 'HTTP_USER_AGENT' ] = 'MyAgent/1.0';
		$_SERVER[ 'REMOTE_ADDR' ] = '198.51.100.1';

		$model = new userRefreshToken( new ObjectId(), new \DateInterval( 'P1D' ), 'tok' );
		$this->assertSame( 'MyAgent/1.0', $model->creatorUserAgentHeader );
		$this->assertSame( '198.51.100.1', $model->creatorIP );
	}

	public function testConstructorAssignsExpirationFromDuration(): void {
		$model = new userRefreshToken( new ObjectId(), new \DateInterval( 'P30D' ), 't' );
		$diff = $model->expiration->getTimestamp() - $model->creation->getTimestamp();
		$this->assertEqualsWithDelta( 30 * 86400, $diff, 5 );
	}

	public function testScopeDefaultsToRefresh(): void {
		$model = new userRefreshToken( new ObjectId(), new \DateInterval( 'P1D' ), 't' );
		$this->assertSame( 'refresh', $model->scope );
	}

	public function testClassIsFinal(): void {
		$this->assertTrue( ( new \ReflectionClass( userRefreshToken::class ) )->isFinal() );
	}

}
