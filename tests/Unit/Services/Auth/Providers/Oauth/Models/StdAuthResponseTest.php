<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Auth\Providers\Oauth\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use gcgov\framework\services\auth\providers\oauth\models\stdAuthResponse;

#[CoversClass(stdAuthResponse::class)]
final class StdAuthResponseTest extends TestCase {

	public function testDefaultsWithNoTokens(): void {
		$response = new stdAuthResponse();
		$this->assertSame( 'Bearer', $response->token_type );
		$this->assertSame( 0, $response->expires_in );
		$this->assertSame( '', $response->access_token );
		$this->assertSame( '', $response->refresh_token );
	}

	public function testAccessTokenPopulatesExpiryAndString(): void {
		$expiresAt = ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) );
		$accessToken = $this->buildToken( [ 'exp' => $expiresAt ] );

		$response = new stdAuthResponse( $accessToken );
		$this->assertEqualsWithDelta( 3600, $response->expires_in, 5 );
		$this->assertSame( $accessToken->toString(), $response->access_token );
		$this->assertSame( 'Bearer', $response->token_type );
		$this->assertSame( '', $response->refresh_token );
	}

	public function testCustomTokenTypeIsRespected(): void {
		$accessToken = $this->buildToken( [ 'exp' => ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT5M' ) ) ] );
		$response = new stdAuthResponse( $accessToken, null, 'MAC' );
		$this->assertSame( 'MAC', $response->token_type );
	}

	public function testRefreshTokenPopulatesRefreshString(): void {
		$accessToken = $this->buildToken( [ 'exp' => ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) ) ] );
		$refreshToken = $this->buildToken( [ 'exp' => ( new \DateTimeImmutable() )->add( new \DateInterval( 'P30D' ) ) ] );

		$response = new stdAuthResponse( $accessToken, $refreshToken );
		$this->assertSame( $refreshToken->toString(), $response->refresh_token );
	}

	public function testRefreshTokenAlonePopulatesOnlyRefreshString(): void {
		$refreshToken = $this->buildToken( [ 'exp' => ( new \DateTimeImmutable() )->add( new \DateInterval( 'PT1H' ) ) ] );
		$response = new stdAuthResponse( null, $refreshToken );
		$this->assertSame( '', $response->access_token );
		$this->assertSame( $refreshToken->toString(), $response->refresh_token );
		$this->assertSame( 'Bearer', $response->token_type );
	}

	/**
	 * @param  array<string, mixed>  $claims
	 */
	private function buildToken( array $claims ): Plain {
		$headers = new DataSet( [ 'typ' => 'JWT', 'alg' => 'none' ], 'eyJ0eXAiOiJKV1QiLCJhbGciOiJub25lIn0' );
		$payload = new DataSet( $claims, 'eyJjbGFpbXMiOiJpbnNpZGUifQ' );
		$signature = new Signature( '', '' );
		return new Plain( $headers, $payload, $signature );
	}

}
