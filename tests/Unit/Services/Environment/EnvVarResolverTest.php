<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Environment;

use gcgov\framework\services\environment\environmentException;
use gcgov\framework\services\environment\envVarResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(envVarResolver::class)]
#[CoversClass(environmentException::class)]
final class EnvVarResolverTest extends TestCase {

	/** @var array<string, string> */
	private array $envSnapshot = [];

	/** @var array<string, mixed> */
	private array $serverSnapshot = [];

	private string $tempDir = '';


	protected function setUp(): void {
		$this->envSnapshot    = $_ENV;
		$this->serverSnapshot = $_SERVER;
		$this->tempDir        = sys_get_temp_dir() . '/gcgov-envresolver-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );
	}


	protected function tearDown(): void {
		// Unset any variables the tests introduced before restoring snapshots.
		foreach( array_keys( $_ENV ) as $key ) {
			if( !array_key_exists( $key, $this->envSnapshot ) ) {
				putenv( $key );
			}
		}
		$_ENV    = $this->envSnapshot;
		$_SERVER = $this->serverSnapshot;

		$this->deleteDirectory( $this->tempDir );
	}


	private function setEnv( string $name, string $value ): void {
		$_ENV[ $name ] = $value;
		putenv( $name . '=' . $value );
	}


	public function testFastPathReturnsIdenticalStringWhenNoEnvReference(): void {
		$json = '{"type":"prod","serverName":"api.example.com","port":8080}';
		$this->assertSame( $json, envVarResolver::resolveJson( $json, 'test' ) );
	}


	public function testFastPathPreservesMalformedJson(): void {
		$json = '{ this is not valid json ';
		$this->assertSame( $json, envVarResolver::resolveJson( $json, 'test' ) );
	}


	public function testInvalidJsonWithEnvReferenceIsPassedThrough(): void {
		// Contains %env( so it leaves the fast path, but is not decodable → raw string back.
		$json = '{ "uri": "%env(MONGO_URI)%" ';
		$this->assertSame( $json, envVarResolver::resolveJson( $json, 'test' ) );
	}


	public function testWholeStringResolvesToTypedString(): void {
		$this->setEnv( 'MONGO_URI', 'mongodb://db:27017' );
		$result = envVarResolver::resolveJson( '{"uri":"%env(MONGO_URI)%"}', 'test' );
		$this->assertInstanceOf( \stdClass::class, $result );
		$this->assertSame( 'mongodb://db:27017', $result->uri );
	}


	public function testIntProcessorYieldsInteger(): void {
		$this->setEnv( 'SMTP_PORT', '2525' );
		$result = envVarResolver::resolveJson( '{"port":"%env(int:SMTP_PORT)%"}', 'test' );
		$this->assertIsInt( $result->port );
		$this->assertSame( 2525, $result->port );
	}


	public function testFloatProcessorYieldsFloat(): void {
		$this->setEnv( 'RATE', '1.5' );
		$result = envVarResolver::resolveJson( '{"rate":"%env(float:RATE)%"}', 'test' );
		$this->assertIsFloat( $result->rate );
		$this->assertSame( 1.5, $result->rate );
	}


	public function testBoolAndNotProcessors(): void {
		$this->setEnv( 'FLAG', 'true' );
		$result = envVarResolver::resolveJson( '{"on":"%env(bool:FLAG)%","off":"%env(not:FLAG)%"}', 'test' );
		$this->assertTrue( $result->on );
		$this->assertFalse( $result->off );
	}


	public function testTrimProcessor(): void {
		$this->setEnv( 'PADDED', "  spaced  \n" );
		$result = envVarResolver::resolveJson( '{"v":"%env(trim:PADDED)%"}', 'test' );
		$this->assertSame( 'spaced', $result->v );
	}


	public function testJsonProcessorYieldsStructure(): void {
		$this->setEnv( 'ROLES', '["a","b"]' );
		$result = envVarResolver::resolveJson( '{"roles":"%env(json:ROLES)%"}', 'test' );
		$this->assertSame( [ 'a', 'b' ], $result->roles );
	}


	public function testBase64ProcessorUrlSafeTolerant(): void {
		// URL-safe base64 of "secret?" without padding
		$this->setEnv( 'SECRET_B64', 'c2VjcmV0Pw' );
		$result = envVarResolver::resolveJson( '{"s":"%env(base64:SECRET_B64)%"}', 'test' );
		$this->assertSame( 'secret?', $result->s );
	}


	public function testEmbeddedReferenceIsStringSubstituted(): void {
		$this->setEnv( 'HOST', 'db.internal' );
		$this->setEnv( 'PORT', '27017' );
		$result = envVarResolver::resolveJson( '{"uri":"mongodb://%env(HOST)%:%env(PORT)%/app"}', 'test' );
		$this->assertSame( 'mongodb://db.internal:27017/app', $result->uri );
	}


	public function testEmbeddedNonScalarThrows(): void {
		$this->setEnv( 'ROLES', '["a"]' );
		$this->expectException( environmentException::class );
		envVarResolver::resolveJson( '{"v":"prefix-%env(json:ROLES)%"}', 'test' );
	}


	public function testDefaultWithColonsInValue(): void {
		// Var unset → greedy default containing colons is used.
		$result = envVarResolver::resolveJson( '{"uri":"%env(default:mongodb://mongodb:27017:MONGO_URI)%"}', 'test' );
		$this->assertSame( 'mongodb://mongodb:27017', $result->uri );
	}


	public function testDefaultEmptyValue(): void {
		$result = envVarResolver::resolveJson( '{"secret":"%env(default::MICROSOFT_CLIENT_SECRET)%"}', 'test' );
		$this->assertSame( '', $result->secret );
	}


	public function testDefaultIsIgnoredWhenVariableIsSet(): void {
		$this->setEnv( 'MONGO_URI', 'mongodb://real:27017' );
		$result = envVarResolver::resolveJson( '{"uri":"%env(default:mongodb://fallback:27017:MONGO_URI)%"}', 'test' );
		$this->assertSame( 'mongodb://real:27017', $result->uri );
	}


	public function testComposedIntDefault(): void {
		$result = envVarResolver::resolveJson( '{"port":"%env(int:default:587:SMTP_PORT)%"}', 'test' );
		$this->assertIsInt( $result->port );
		$this->assertSame( 587, $result->port );
	}


	public function testTrimFileChainReadsSecretFile(): void {
		$secretFile = $this->tempDir . '/mongo_uri';
		file_put_contents( $secretFile, "mongodb://secret:27017\n" );
		$this->setEnv( 'MONGO_URI_FILE', $secretFile );
		$result = envVarResolver::resolveJson( '{"uri":"%env(trim:file:MONGO_URI_FILE)%"}', 'test' );
		$this->assertSame( 'mongodb://secret:27017', $result->uri );
	}


	public function testMissingVariableMessageContainsNameAndSource(): void {
		try {
			envVarResolver::resolveJson( '{"uri":"%env(MONGO_URI)%"}', '/app/config/environment.json' );
			$this->fail( 'Expected environmentException' );
		}
		catch( environmentException $e ) {
			$this->assertStringContainsString( 'MONGO_URI', $e->getMessage() );
			$this->assertStringContainsString( '/app/config/environment.json', $e->getMessage() );
		}
	}


	public function testUnknownProcessorThrows(): void {
		$this->setEnv( 'X', 'y' );
		$this->expectException( environmentException::class );
		envVarResolver::resolveJson( '{"v":"%env(bogus:X)%"}', 'test' );
	}


	public function testNestedAppDictionaryResolution(): void {
		$this->setEnv( 'CRON_URL', 'https://monitor.example.com/hook' );
		$this->setEnv( 'MAX_ITEMS', '25' );
		$json   = '{"appDictionary":{"cronMonitorUrl":"%env(CRON_URL)%","limits":{"maxItems":"%env(int:MAX_ITEMS)%"}}}';
		$result = envVarResolver::resolveJson( $json, 'test' );
		$this->assertSame( 'https://monitor.example.com/hook', $result->appDictionary->cronMonitorUrl );
		$this->assertSame( 25, $result->appDictionary->limits->maxItems );
	}


	public function testOverlayBeatsAmbientEnvironment(): void {
		$this->setEnv( 'MONGO_URI', 'mongodb://local:27017' );
		$result = envVarResolver::resolveJson( '{"uri":"%env(MONGO_URI)%"}', 'test', [ 'MONGO_URI' => 'mongodb://prod:27017' ] );
		$this->assertSame( 'mongodb://prod:27017', $result->uri );
	}


	public function testOverlayMissFallsBackToAmbient(): void {
		$this->setEnv( 'MONGO_URI', 'mongodb://local:27017' );
		$result = envVarResolver::resolveJson( '{"uri":"%env(MONGO_URI)%","db":"%env(MONGO_DATABASE)%"}', 'test', [ 'MONGO_DATABASE' => 'prodDb' ] );
		$this->assertSame( 'mongodb://local:27017', $result->uri );
		$this->assertSame( 'prodDb', $result->db );
	}


	public function testOverlayValueSuppressesDefault(): void {
		$result = envVarResolver::resolveJson( '{"uri":"%env(default:mongodb://fallback:27017:MONGO_URI)%"}', 'test', [ 'MONGO_URI' => 'mongodb://overlay:27017' ] );
		$this->assertSame( 'mongodb://overlay:27017', $result->uri );
	}


	public function testEmptyOverlayValueResolvesToEmptyStringAndSuppressesDefault(): void {
		$result = envVarResolver::resolveJson( '{"secret":"%env(default:fallback:CLIENT_SECRET)%"}', 'test', [ 'CLIENT_SECRET' => '' ] );
		$this->assertSame( '', $result->secret );
	}


	public function testEmptyOverlayArrayIsIdenticalToTwoArgCall(): void {
		$this->setEnv( 'MONGO_URI', 'mongodb://ambient:27017' );
		$json = '{"uri":"%env(MONGO_URI)%","port":"%env(int:default:587:SMTP_PORT)%"}';
		$this->assertEquals( envVarResolver::resolveJson( $json, 'test' ), envVarResolver::resolveJson( $json, 'test', [] ) );
	}


	public function testOverlayWorksWithProcessorsAndEmbeddedRefs(): void {
		$result = envVarResolver::resolveJson( '{"port":"%env(int:SMTP_PORT)%","url":"https://%env(HOSTNAME_X)%/api"}', 'test', [ 'SMTP_PORT' => '2525', 'HOSTNAME_X' => 'prod.example.com' ] );
		$this->assertSame( 2525, $result->port );
		$this->assertSame( 'https://prod.example.com/api', $result->url );
	}


	public function testServerHttpKeysAreNotUsedForLookup(): void {
		// A malicious request header must not satisfy an env reference.
		$_SERVER[ 'HTTP_MONGO_URI' ] = 'mongodb://attacker';
		$this->expectException( environmentException::class );
		try {
			envVarResolver::resolveJson( '{"uri":"%env(HTTP_MONGO_URI)%"}', 'test' );
		}
		finally {
			unset( $_SERVER[ 'HTTP_MONGO_URI' ] );
		}
	}


	private function deleteDirectory( string $directory ): void {
		if( !is_dir( $directory ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $directory );
	}

}
