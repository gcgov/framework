<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use MongoDB\BSON\ObjectId;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use gcgov\framework\services\auth\providers\oauth\models\configureMfaResponse;
use gcgov\framework\services\auth\providers\oauth\models\stdAuthResponse;
use gcgov\framework\services\mongodb\models\auth\userMultifactor;

#[CoversClass(configureMfaResponse::class)]
final class ConfigureMfaResponseTest extends TestCase {

	public function testExtendsStdAuthResponse(): void {
		$this->assertTrue( is_subclass_of( configureMfaResponse::class, stdAuthResponse::class ) );
	}

	public function testConstructorPopulatesAllFieldsFromUserMultifactor(): void {
		$userId = new ObjectId();
		$mf = new userMultifactor( $userId );
		$mf->secret = 'SECRETXYZ';

		$token = $this->buildToken();
		$response = new configureMfaResponse( $token, $mf, 'data:image/png;base64,abc' );

		$this->assertSame( 'data:image/png;base64,abc', $response->qrCodeDataUri );
		$this->assertSame( 'SECRETXYZ', $response->secret );
		$this->assertSame( (string) $userId, (string) $response->userId );
		$this->assertSame( (string) $mf->_id, (string) $response->userMultifactorId );
		$this->assertTrue( $response->mfaRequired );
		$this->assertFalse( $response->mfaConfigured );
		$this->assertSame( $token->toString(), $response->access_token );
	}

	public function testQrCodeDataUriDefaultsToEmptyString(): void {
		$mf = new userMultifactor( new ObjectId() );
		$response = new configureMfaResponse( null, $mf );
		$this->assertSame( '', $response->qrCodeDataUri );
	}

	public function testNullAccessTokenLeavesAccessTokenEmpty(): void {
		$mf = new userMultifactor( new ObjectId() );
		$response = new configureMfaResponse( null, $mf, 'x' );
		$this->assertSame( '', $response->access_token );
		$this->assertSame( 0, $response->expires_in );
	}

	private function buildToken(): Plain {
		$exp = ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) );
		return new Plain(
			new DataSet( [ 'typ' => 'JWT', 'alg' => 'none' ], 'header' ),
			new DataSet( [ 'exp' => $exp ], 'payload' ),
			new Signature( '', '' )
		);
	}

}
