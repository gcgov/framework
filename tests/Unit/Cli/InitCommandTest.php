<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\initCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `gf init` rewrites config.json in place to stamp the application's title and guid.
 *
 * In config.json `{}` carries meaning — `"services": { "userCrud": {} }` is how a Framework
 * Service is enabled, because presence is what activates it. json_decode($raw, true) maps an
 * empty object to [], which json_encode writes back as [], so the round-trip silently
 * disabled every service the template declared on the first command a new project runs.
 */
#[CoversClass(initCommand::class)]
final class InitCommandTest extends TestCase {

	public function testEmptyServiceBlocksSurviveTheRewrite(): void {
		$original = '{"app":{"title":"","guid":""},"services":{"userCrud":{},"documentation":{}},"appDictionary":{}}';

		$rewritten = initCommand::applyIdentity( $original, 'Timesheet API', 'abc-123' )[ 'json' ];

		self::assertSame( '{}', $this->encodedFragment( $rewritten, [ 'services', 'userCrud' ] ) );
		self::assertSame( '{}', $this->encodedFragment( $rewritten, [ 'services', 'documentation' ] ) );
		self::assertSame( '{}', $this->encodedFragment( $rewritten, [ 'appDictionary' ] ) );
	}


	public function testRewrittenConfigStillHydratesItsServices(): void {
		$original = '{"app":{"title":"","guid":""},"services":{"userCrud":{},"documentation":{}}}';

		$rewritten = initCommand::applyIdentity( $original, 'Timesheet API', 'abc-123' )[ 'json' ];
		$config    = \gcgov\framework\models\unifiedConfig::jsonDeserialize( $rewritten );

		self::assertNotNull( $config->services->userCrud, 'an empty block must still enable the service' );
		self::assertNotNull( $config->services->documentation );
	}


	public function testTitleAndGuidAreStamped(): void {
		$identity = initCommand::applyIdentity( '{"app":{"title":"","guid":""}}', 'Timesheet API', 'abc-123' );
		$decoded  = json_decode( $identity[ 'json' ], false );

		self::assertSame( 'Timesheet API', $decoded->app->title );
		self::assertSame( 'abc-123', $decoded->app->guid );
		self::assertSame( 'Timesheet API', $identity[ 'title' ] );
		self::assertFalse( $identity[ 'guidKept' ] );
	}


	/** The guid is the OAuth client_id: reminting it would invalidate every registered client. */
	public function testExistingGuidIsKeptWhenNoneIsSupplied(): void {
		$identity = initCommand::applyIdentity( '{"app":{"title":"Old","guid":"keep-me"}}', 'New Title', '' );
		$decoded  = json_decode( $identity[ 'json' ], false );

		self::assertSame( 'keep-me', $decoded->app->guid );
		self::assertSame( 'New Title', $decoded->app->title );
		self::assertTrue( $identity[ 'guidKept' ] );
	}


	public function testAGuidIsMintedWhenThereIsNone(): void {
		$identity = initCommand::applyIdentity( '{"app":{"title":"","guid":""}}', 'API', '' );

		self::assertNotSame( '', $identity[ 'guid' ] );
		self::assertFalse( $identity[ 'guidKept' ] );
	}


	public function testUnrelatedSectionsAreLeftIntact(): void {
		$original = '{"app":{"title":"","guid":""},"mongoDatabases":[{"default":true,"database":"appdb"}],"type":"local"}';

		$decoded = json_decode( initCommand::applyIdentity( $original, 'API', 'g' )[ 'json' ], false );

		self::assertSame( 'local', $decoded->type );
		self::assertSame( 'appdb', $decoded->mongoDatabases[ 0 ]->database );
	}


	public function testNonObjectJsonIsRejected(): void {
		$this->expectException( \gcgov\framework\cli\cliException::class );

		initCommand::applyIdentity( '[1,2,3]', 'API', 'g' );
	}


	/** @param string[] $path */
	private function encodedFragment( string $json, array $path ): string {
		$value = json_decode( $json, false );
		foreach( $path as $key ) {
			self::assertObjectHasProperty( $key, $value, 'expected ' . implode( '.', $path ) . ' to survive' );
			$value = $value->{$key};
		}

		return (string)json_encode( $value );
	}

}
