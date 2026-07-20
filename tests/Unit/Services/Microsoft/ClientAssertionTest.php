<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Microsoft;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\exceptions\serviceException;
use gcgov\framework\services\microsoft\clientAssertion;

#[CoversClass(clientAssertion::class)]
final class ClientAssertionTest extends TestCase {

	private static string $certificatePem = '';

	private static string $privateKeyPem = '';


	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		if( $key===false ) {
			self::fail( 'Unable to generate test RSA key: ' . (string) openssl_error_string() );
		}

		$csr  = openssl_csr_new( [ 'commonName' => 'client-assertion-test' ], $key, [ 'digest_alg' => 'sha256' ] );
		$x509 = openssl_csr_sign( $csr, null, $key, 365, [ 'digest_alg' => 'sha256' ] );
		if( $x509===false ) {
			self::fail( 'Unable to generate test certificate: ' . (string) openssl_error_string() );
		}

		openssl_x509_export( $x509, self::$certificatePem );
		openssl_pkey_export( $key, self::$privateKeyPem );
	}


	/** SHA-1 of the DER certificate computed independently of openssl_x509_fingerprint */
	private static function rawSha1OfDer(): string {
		$pemBody = preg_replace( '/-----[A-Z ]+-----|\s/', '', self::$certificatePem );
		return sha1( (string) base64_decode( (string) $pemBody ), true );
	}


	private static function base64UrlDecode( string $data ): string {
		return (string) base64_decode( strtr( $data, '-_', '+/' ) );
	}


	public function testGetCertificateThumbprintMatchesDerSha1(): void {
		$expected = strtoupper( bin2hex( self::rawSha1OfDer() ) );
		$this->assertSame( $expected, clientAssertion::getCertificateThumbprint( self::$certificatePem ) );
	}


	public function testGetCertificateX5tIsBase64UrlOfDerSha1(): void {
		$expected = rtrim( strtr( base64_encode( self::rawSha1OfDer() ), '+/', '-_' ), '=' );
		$this->assertSame( $expected, clientAssertion::getCertificateX5t( self::$certificatePem ) );
	}


	public function testThumbprintThrowsOnInvalidCertificate(): void {
		$this->expectException( serviceException::class );
		clientAssertion::getCertificateThumbprint( 'not a certificate' );
	}


	public function testCreateFromPartsProducesValidAssertion(): void {
		$jwt = clientAssertion::createFromParts( 'test-client-id', 'test-tenant-id', self::$certificatePem, self::$privateKeyPem );

		$segments = explode( '.', $jwt );
		$this->assertCount( 3, $segments );

		$header = json_decode( self::base64UrlDecode( $segments[ 0 ] ), true );
		$this->assertSame( 'RS256', $header[ 'alg' ] );
		$this->assertSame( 'JWT', $header[ 'typ' ] );
		$this->assertSame( clientAssertion::getCertificateX5t( self::$certificatePem ), $header[ 'x5t' ] );

		$claims = json_decode( self::base64UrlDecode( $segments[ 1 ] ), true );
		$this->assertSame( 'test-client-id', $claims[ 'iss' ] );
		$this->assertSame( 'test-client-id', $claims[ 'sub' ] );
		$this->assertSame( 'https://login.microsoftonline.com/test-tenant-id/oauth2/v2.0/token', $claims[ 'aud' ] );
		$this->assertNotEmpty( $claims[ 'jti' ] );
		$this->assertIsInt( $claims[ 'iat' ] );
		$this->assertIsInt( $claims[ 'nbf' ] );
		$this->assertIsInt( $claims[ 'exp' ] );
		$this->assertGreaterThan( $claims[ 'iat' ], $claims[ 'exp' ] );
		$this->assertSame( 600, $claims[ 'exp' ] - $claims[ 'nbf' ] );

		//signature must verify against the certificate public key
		$publicKey = openssl_pkey_get_public( self::$certificatePem );
		$this->assertNotFalse( $publicKey );
		$verified = openssl_verify( $segments[ 0 ] . '.' . $segments[ 1 ], self::base64UrlDecode( $segments[ 2 ] ), $publicKey, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $verified );
	}


	public function testCreateFromPartsHonorsExplicitAudience(): void {
		$audience = 'https://login.microsoftonline.com/test-tenant-id/oauth2/token';
		$jwt      = clientAssertion::createFromParts( 'test-client-id', 'test-tenant-id', self::$certificatePem, self::$privateKeyPem, '', $audience );

		$segments = explode( '.', $jwt );
		$claims   = json_decode( self::base64UrlDecode( $segments[ 1 ] ), true );
		$this->assertSame( $audience, $claims[ 'aud' ] );
	}


	public function testCreateFromPartsGeneratesUniqueJti(): void {
		$jtis = [];
		for( $i = 0; $i < 2; $i++ ) {
			$jwt      = clientAssertion::createFromParts( 'test-client-id', 'test-tenant-id', self::$certificatePem, self::$privateKeyPem );
			$segments = explode( '.', $jwt );
			$claims   = json_decode( self::base64UrlDecode( $segments[ 1 ] ), true );
			$jtis[]   = $claims[ 'jti' ];
		}
		$this->assertNotSame( $jtis[ 0 ], $jtis[ 1 ] );
	}


	public function testCreateFromPartsRejectsMismatchedKey(): void {
		$otherKey = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		$otherKeyPem = '';
		openssl_pkey_export( $otherKey, $otherKeyPem );

		$this->expectException( serviceException::class );
		clientAssertion::createFromParts( 'test-client-id', 'test-tenant-id', self::$certificatePem, $otherKeyPem );
	}


	public function testCreateFromPartsSupportsPassphraseProtectedKey(): void {
		$key = openssl_pkey_get_private( self::$privateKeyPem );
		$encryptedKeyPem = '';
		openssl_pkey_export( $key, $encryptedKeyPem, 'test-passphrase' );

		$jwt = clientAssertion::createFromParts( 'test-client-id', 'test-tenant-id', self::$certificatePem, $encryptedKeyPem, 'test-passphrase' );
		$this->assertCount( 3, explode( '.', $jwt ) );
	}


	public function testGetDecryptedPrivateKeyPemRoundTrips(): void {
		$key = openssl_pkey_get_private( self::$privateKeyPem );
		$encryptedKeyPem = '';
		openssl_pkey_export( $key, $encryptedKeyPem, 'test-passphrase' );

		$decryptedPem = clientAssertion::getDecryptedPrivateKeyPem( $encryptedKeyPem, 'test-passphrase' );
		$this->assertStringNotContainsString( 'ENCRYPTED', $decryptedPem );

		//decrypted key must still pair with the certificate
		$this->assertTrue( openssl_x509_check_private_key( self::$certificatePem, openssl_pkey_get_private( $decryptedPem ) ) );
	}


	public function testGetDecryptedPrivateKeyPemPassesThroughUnencryptedKey(): void {
		$this->assertSame( self::$privateKeyPem, clientAssertion::getDecryptedPrivateKeyPem( self::$privateKeyPem ) );
	}


	public function testCreateUsesEnvironmentConfig(): void {
		$certificatePath = tempnam( sys_get_temp_dir(), 'cert' );
		$privateKeyPath  = tempnam( sys_get_temp_dir(), 'key' );
		file_put_contents( $certificatePath, self::$certificatePem );
		file_put_contents( $privateKeyPath, self::$privateKeyPem );

		$microsoftConfig = \gcgov\framework\config::getEnvironmentConfig()->microsoft;
		$originalConfig  = clone $microsoftConfig;

		try {
			$microsoftConfig->clientId        = 'env-client-id';
			$microsoftConfig->tenant          = 'env-tenant-id';
			$microsoftConfig->certificatePath = $certificatePath;
			$microsoftConfig->privateKeyPath  = $privateKeyPath;

			$jwt      = clientAssertion::create();
			$segments = explode( '.', $jwt );
			$claims   = json_decode( self::base64UrlDecode( $segments[ 1 ] ), true );

			$this->assertSame( 'env-client-id', $claims[ 'iss' ] );
			$this->assertSame( 'https://login.microsoftonline.com/env-tenant-id/oauth2/v2.0/token', $claims[ 'aud' ] );
		}
		finally {
			\gcgov\framework\config::getEnvironmentConfig()->microsoft = $originalConfig;
			unlink( $certificatePath );
			unlink( $privateKeyPath );
		}
	}


	public function testCreateThrowsWhenCertificateAuthNotConfigured(): void {
		$this->assertFalse( \gcgov\framework\config::getEnvironmentConfig()->microsoft->useCertificateAuthentication() );

		$this->expectException( serviceException::class );
		clientAssertion::create();
	}

}
