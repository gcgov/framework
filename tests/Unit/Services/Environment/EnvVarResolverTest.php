<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Environment;

use gcgov\framework\services\environment\environmentException;
use gcgov\framework\services\environment\envVarResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(envVarResolver::class)]
final class EnvVarResolverTest extends TestCase {

	/** @var array<string, string|false> */
	private array $originalEnv = [];


	protected function tearDown(): void {
		foreach( array_keys( $this->originalEnv ) as $name ) {
			unset( $_ENV[ $name ], $_SERVER[ $name ] );
			putenv( $name );
		}
		$this->originalEnv = [];

		parent::tearDown();
	}


	private function setEnv( string $name, string $value ): void {
		$this->originalEnv[ $name ] = getenv( $name );
		$_ENV[ $name ]              = $value;
		putenv( $name . '=' . $value );
	}


	/** Track a name so tearDown cleans it, without setting it. */
	private function trackEnv( string $name ): void {
		$this->originalEnv[ $name ] = getenv( $name );
	}


	private function resolve( string $json ): \stdClass {
		$resolved = envVarResolver::resolveJson( $json, 'test config' );
		self::assertInstanceOf( \stdClass::class, $resolved );

		return $resolved;
	}


	// --- opting in -------------------------------------------------------------

	public function testConfigWithoutAnyReferenceIsReturnedByteForByte(): void {
		$json = '{ "not json at all';
		self::assertSame( $json, envVarResolver::resolveJson( $json, 'test config' ) );
	}


	public function testMalformedJsonContainingAReferenceIsHandedBackForTheCallerToReport(): void {
		$json = '{ "uri": "%env(ANYTHING)%"';
		self::assertSame( $json, envVarResolver::resolveJson( $json, 'test config' ) );
	}


	// --- required references ---------------------------------------------------

	public function testWholeStringReferenceIsReplacedWithTheValue(): void {
		$this->setEnv( 'GF_TEST_URI', 'mongodb://db:27017' );

		self::assertSame( 'mongodb://db:27017', $this->resolve( '{"uri":"%env(GF_TEST_URI)%"}' )->uri );
	}


	public function testEmbeddedReferenceIsSubstitutedAsAString(): void {
		$this->setEnv( 'GF_TEST_HOST', 'example.org' );

		self::assertSame( 'https://example.org/api', $this->resolve( '{"url":"https://%env(GF_TEST_HOST)%/api"}' )->url );
	}


	public function testMissingVariableThrowsNamingIt(): void {
		$this->trackEnv( 'GF_TEST_ABSENT' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/GF_TEST_ABSENT/' );
		$this->resolve( '{"uri":"%env(GF_TEST_ABSENT)%"}' );
	}


	/**
	 * The behaviour that makes a copied .env full of blank placeholders fail loudly
	 * instead of silently configuring an application with empty credentials.
	 */
	public function testVariableSetToTheEmptyStringCountsAsUnset(): void {
		$this->setEnv( 'GF_TEST_BLANK', '' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/GF_TEST_BLANK/' );
		$this->resolve( '{"secret":"%env(GF_TEST_BLANK)%"}' );
	}


	public function testDefaultProcessorNoLongerExists(): void {
		$this->trackEnv( 'GF_TEST_ABSENT' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/Unknown environment processor "default"/' );
		$this->resolve( '{"type":"%env(default:local:GF_TEST_ABSENT)%"}' );
	}


	/** @return iterable<string, array{0: string}> */
	public static function removedProcessorProvider(): iterable {
		yield 'not' => [ 'not' ];
		yield 'float' => [ 'float' ];
		yield 'base64' => [ 'base64' ];
		yield 'string' => [ 'string' ];
	}


	#[DataProvider('removedProcessorProvider')]
	public function testRemovedProcessorsAreRejected( string $processor ): void {
		$this->setEnv( 'GF_TEST_VALUE', '1' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/Unknown environment processor "' . $processor . '"/' );
		$this->resolve( '{"v":"%env(' . $processor . ':GF_TEST_VALUE)%"}' );
	}


	// --- surviving processors --------------------------------------------------

	public function testIntProcessorProducesATypedInt(): void {
		$this->setEnv( 'GF_TEST_PORT', '587' );

		self::assertSame( 587, $this->resolve( '{"port":"%env(int:GF_TEST_PORT)%"}' )->port );
	}


	public function testBoolProcessorProducesATypedBool(): void {
		$this->setEnv( 'GF_TEST_FLAG', 'true' );

		self::assertTrue( $this->resolve( '{"flag":"%env(bool:GF_TEST_FLAG)%"}' )->flag );
	}


	/**
	 * ADR 0001: the bool processor fails closed. It used to fall back to `(bool)$value`,
	 * which turned every unrecognised value — "flase", "disabled", "2" — into TRUE with
	 * no error, so a typo'd AUTH_BLOCK_NEW_USERS resolved silently to the wrong setting
	 * while `gf env` reported success.
	 */
	public function testBoolProcessorRejectsAnUnrecognisedValue(): void {
		$this->setEnv( 'GF_TEST_FLAG', 'flase' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/bool/' );
		$this->resolve( '{"flag":"%env(bool:GF_TEST_FLAG)%"}' );
	}


	public function testIsSatisfiedAcceptsThePlainOrTheFileName(): void {
		$this->trackEnv( 'GF_TEST_SAT' );
		self::assertFalse( envVarResolver::isSatisfied( 'GF_TEST_SAT' ) );

		$this->setEnv( 'GF_TEST_SAT' . envVarResolver::SECRET_FILE_SUFFIX, '/run/secrets/app/sat' );
		self::assertTrue( envVarResolver::isSatisfied( 'GF_TEST_SAT' ) );
	}


	/** A reserved CGI name is never satisfiable, even when genuinely set. */
	public function testIsSatisfiedIsFalseForAReservedNameEvenWhenSet(): void {
		$this->setEnv( 'SERVER_API_TOKEN', 'value' );

		self::assertFalse( envVarResolver::isSatisfied( 'SERVER_API_TOKEN' ) );
	}


	public function testIsReservedNameMatchesPrefixesAndExactNames(): void {
		self::assertTrue( envVarResolver::isReservedName( 'SERVER_API_TOKEN' ) );
		self::assertTrue( envVarResolver::isReservedName( 'CONTENT_TYPE' ) );
		self::assertFalse( envVarResolver::isReservedName( 'MONGO_URI' ) );
	}


	public function testProcessorsApplyRightToLeft(): void {
		$path = tempnam( sys_get_temp_dir(), 'gf' );
		self::assertIsString( $path );
		file_put_contents( $path, "  42\n" );
		$this->setEnv( 'GF_TEST_FILE', $path );

		// int(trim(file(env))) — innermost first.
		self::assertSame( 42, $this->resolve( '{"n":"%env(int:trim:file:GF_TEST_FILE)%"}' )->n );

		unlink( $path );
	}


	public function testUnknownProcessorIsRejected(): void {
		$this->setEnv( 'GF_TEST_VALUE', 'x' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/Unknown environment processor "rot13"/' );
		$this->resolve( '{"v":"%env(rot13:GF_TEST_VALUE)%"}' );
	}


	// --- the secret lookup -----------------------------------------------------

	public function testSecretFallsBackToThePlainVariableWhenNoFileIsNamed(): void {
		$this->setEnv( 'GF_TEST_MONGO', 'mongodb://localhost' );
		$this->trackEnv( 'GF_TEST_MONGO_FILE' );

		self::assertSame( 'mongodb://localhost', $this->resolve( '{"uri":"%env(secret:GF_TEST_MONGO)%"}' )->uri );
	}


	public function testSecretPrefersTheFileAndTrimsIt(): void {
		$path = tempnam( sys_get_temp_dir(), 'gf' );
		self::assertIsString( $path );
		file_put_contents( $path, "mongodb://from-file\n" );

		$this->setEnv( 'GF_TEST_MONGO', 'mongodb://from-environment' );
		$this->setEnv( 'GF_TEST_MONGO_FILE', $path );

		self::assertSame( 'mongodb://from-file', $this->resolve( '{"uri":"%env(secret:GF_TEST_MONGO)%"}' )->uri );

		unlink( $path );
	}


	/**
	 * The rule that keeps a failed secret mount from silently resolving to whatever
	 * stale value happens to be in the environment.
	 */
	public function testSecretFileThatIsMissingIsAnErrorAndNeverFallsBack(): void {
		$this->setEnv( 'GF_TEST_MONGO', 'mongodb://from-environment' );
		$this->setEnv( 'GF_TEST_MONGO_FILE', '/nonexistent/secret/mongo_uri' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/not falling back to GF_TEST_MONGO/' );
		$this->resolve( '{"uri":"%env(secret:GF_TEST_MONGO)%"}' );
	}


	public function testSecretReportsBothNamesWhenNeitherIsSet(): void {
		$this->trackEnv( 'GF_TEST_MONGO' );
		$this->trackEnv( 'GF_TEST_MONGO_FILE' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/GF_TEST_MONGO_FILE/' );
		$this->resolve( '{"uri":"%env(secret:GF_TEST_MONGO)%"}' );
	}


	public function testSecretMustBeTheInnermostProcessor(): void {
		$this->setEnv( 'GF_TEST_MONGO', 'x' );

		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/"secret" must be the innermost processor/' );
		$this->resolve( '{"uri":"%env(secret:trim:GF_TEST_MONGO)%"}' );
	}


	// --- request-data injection guard -----------------------------------------

	public function testReservedCgiNamesAreNeverResolvedFromTheEnvironment(): void {
		$_SERVER[ 'HTTP_X_INJECTED' ] = 'attacker-controlled';

		try {
			$this->expectException( environmentException::class );
			$this->expectExceptionMessageMatches( '/reserved CGI meta-variable/' );
			$this->resolve( '{"v":"%env(HTTP_X_INJECTED)%"}' );
		}
		finally {
			unset( $_SERVER[ 'HTTP_X_INJECTED' ] );
		}
	}


	// --- malformed syntax ------------------------------------------------------

	public function testUnterminatedReferenceIsAnErrorRatherThanShippedVerbatim(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/Unresolvable %env/' );
		$this->resolve( '{"v":"prefix %env(GF_TEST_UNTERMINATED"}' );
	}


	public function testInvalidVariableNameIsRejected(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessageMatches( '/is not a valid variable name/' );
		$this->resolve( '{"v":"%env(trim:not a name)%"}' );
	}


	// --- reference enumeration -------------------------------------------------

	public function testCollectReferencesFindsEveryVariableWithoutResolvingThem(): void {
		$decoded = json_decode( '{
			"type": "%env(APP_TYPE)%",
			"nested": { "uri": "%env(secret:MONGO_URI)%" },
			"list": [ { "url": "https://%env(APP_HOST)%/api" } ],
			"literal": "no reference here"
		}', false );
		self::assertInstanceOf( \stdClass::class, $decoded );

		$references = envVarResolver::collectReferences( $decoded, 'test config' );

		self::assertSame( [ 'APP_TYPE' => false, 'MONGO_URI' => true, 'APP_HOST' => false ], $references );
	}


	public function testCollectReferencesTreatsANameUsedBothWaysAsASecret(): void {
		$decoded = json_decode( '{"a":"%env(MONGO_URI)%","b":"%env(secret:MONGO_URI)%"}', false );
		self::assertInstanceOf( \stdClass::class, $decoded );

		self::assertSame( [ 'MONGO_URI' => true ], envVarResolver::collectReferences( $decoded, 'test config' ) );
	}

}
