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


	public function testResolveDecodedResolvesInPlace(): void {
		$this->setEnv( 'RD_URI', 'mongodb://rd:27017' );
		$decoded = json_decode( '{"a":{"uri":"%env(RD_URI)%"}}', false );
		$result  = envVarResolver::resolveDecoded( $decoded, 'test' );
		$this->assertSame( $decoded, $result );
		$this->assertSame( 'mongodb://rd:27017', $result->a->uri );
	}


	// --- request-data injection guard (see BLOCKED_NAME_PREFIXES/BLOCKED_NAMES) ---

	public function testHttpPrefixedNameIsNeverResolvedFromServer(): void {
		// A malicious request header exposed via $_SERVER must not satisfy an env reference.
		$_SERVER[ 'HTTP_MONGO_URI' ] = 'mongodb://attacker';
		$this->expectException( environmentException::class );
		try {
			envVarResolver::resolveJson( '{"uri":"%env(HTTP_MONGO_URI)%"}', 'test' );
		}
		finally {
			unset( $_SERVER[ 'HTTP_MONGO_URI' ] );
		}
	}


	public function testHttpPrefixedNameIsNeverResolvedFromGetenv(): void {
		// Under CGI/FastCGI the header reaches the real process env; the guard must
		// still hold at the getenv() fallback, not just $_SERVER.
		putenv( 'HTTP_EVIL_VAR=attacker' );
		try {
			envVarResolver::resolveJson( '{"v":"%env(HTTP_EVIL_VAR)%"}', 'test' );
			$this->fail( 'Expected environmentException' );
		}
		catch( environmentException ) {
			$this->addToAssertionCount( 1 );
		}
		finally {
			putenv( 'HTTP_EVIL_VAR' );
		}
	}


	public function testServerMetaVariableNameIsNeverResolved(): void {
		// $_SERVER['SERVER_NAME'] is request-derived (Host header); a %env(SERVER_NAME)
		// reference must fail loud, not silently bind to the request value.
		$_SERVER[ 'SERVER_NAME' ] = 'evil.host';
		try {
			envVarResolver::resolveJson( '{"v":"%env(SERVER_NAME)%"}', 'test' );
			$this->fail( 'Expected environmentException' );
		}
		catch( environmentException $e ) {
			$this->assertStringContainsString( 'reserved', $e->getMessage() );
		}
		// (leave $_SERVER['SERVER_NAME'] — it is part of the real server env; restored in tearDown)
	}


	public function testBlockedNameStillAllowsDefaultFallback(): void {
		// A blocked name is treated as unset, so an explicit default: still applies.
		$_SERVER[ 'HTTP_X' ] = 'attacker';
		try {
			$result = envVarResolver::resolveJson( '{"v":"%env(default:safe:HTTP_X)%"}', 'test' );
			$this->assertSame( 'safe', $result->v );
		}
		finally {
			unset( $_SERVER[ 'HTTP_X' ] );
		}
	}


	// --- fail-loud on unresolvable references ---

	public function testParenInsideDefaultLiteralThrowsInsteadOfSilentPassthrough(): void {
		$this->expectException( environmentException::class );
		envVarResolver::resolveJson( '{"v":"%env(default:pa)ss:SOME_UNSET_VAR_X)%"}', 'test' );
	}


	public function testLiteralEnvPrefixInValueThrows(): void {
		// A value containing the literal '%env(' that isn't a valid reference must not
		// ship unresolved.
		$this->expectException( environmentException::class );
		envVarResolver::resolveJson( '{"v":"prefix %env( not a ref"}', 'test' );
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
