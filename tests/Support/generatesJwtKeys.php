<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Support;

use gcgov\framework\models\unifiedConfig;

/**
 * Real RSA signing keys in a temporary directory, with the configuration pointed at them.
 *
 * jwtAuth and the auth guard cannot be exercised past "the token did not parse" without
 * keys to sign with, and the signer refuses anything under 2048 bits, so generation is
 * the only honest fixture. Keys are generated once per test class, in setUpBeforeClass(),
 * because a 2048-bit keypair costs real time. Requires {@see seedsFrameworkConfig}.
 */
trait generatesJwtKeys {

	use seedsFrameworkConfig;

	private static string $jwtKeyDir = '';

	/** @var string[] the guids guids.json lists */
	private static array $jwtKeyGuids = [];


	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if( !extension_loaded( 'openssl' ) ) {
			self::markTestSkipped( 'ext-openssl not loaded' );
		}

		self::$jwtKeyDir = sys_get_temp_dir() . '/gcgov-jwt-keys-' . uniqid() . '/';
		mkdir( self::$jwtKeyDir, 0700, true );

		self::$jwtKeyGuids = [ self::writeKeypair( 'aaaaaaaa-0000-0000-0000-000000000001' ), self::writeKeypair( 'aaaaaaaa-0000-0000-0000-000000000002' ) ];
		file_put_contents( self::$jwtKeyDir . 'guids.json', json_encode( self::$jwtKeyGuids ) );
	}


	public static function tearDownAfterClass(): void {
		foreach( glob( self::$jwtKeyDir . '*' ) ?: [] as $file ) {
			unlink( $file );
		}
		if( is_dir( self::$jwtKeyDir ) ) {
			rmdir( self::$jwtKeyDir );
		}

		parent::tearDownAfterClass();
	}


	/** Write private-{guid}.pem and public-{guid}.pem beside guids.json; returns the guid. */
	protected static function writeKeypair( string $guid ): string {
		$privateKey = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if( $privateKey===false ) {
			self::markTestSkipped( 'openssl_pkey_new() failed: ' . (string)openssl_error_string() );
		}
		openssl_pkey_export( $privateKey, $privateKeyPem );
		$details = openssl_pkey_get_details( $privateKey );

		file_put_contents( self::$jwtKeyDir . 'private-' . $guid . '.pem', $privateKeyPem );
		file_put_contents( self::$jwtKeyDir . 'public-' . $guid . '.pem', $details[ 'key' ] );

		return $guid;
	}


	/** Seed a configuration whose jwtAuth.keyPath is the generated key directory. */
	protected function seedConfigWithJwtKeys( ?callable $mutate = null ): unifiedConfig {
		return $this->seedConfig( static function( unifiedConfig $c ) use ( $mutate ): void {
			$c->rootUrl          = 'http://test.local';
			$c->basePath         = 'api';
			$c->type             = 'local';
			$c->jwtAuth->keyPath = self::$jwtKeyDir;
			if( $mutate!==null ) {
				$mutate( $c );
			}
		} );
	}

}
