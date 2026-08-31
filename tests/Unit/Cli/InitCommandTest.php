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

	private string $tempRootDir = '';


	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-init-test-' . uniqid();
		mkdir( $this->tempRootDir . '/vendor', 0777, true );
		mkdir( $this->tempRootDir . '/app', 0777, true );
		touch( $this->tempRootDir . '/vendor/autoload.php' );
		touch( $this->tempRootDir . '/app/app.php' );
		touch( $this->tempRootDir . '/composer.json' );
		file_put_contents( $this->tempRootDir . '/config.json', '{"app":{"title":"","guid":""},"type":"%env(APP_TYPE)%","mongoDatabases":[{"default":true,"database":"%env(MONGO_DATABASE)%","uri":"%env(secret:MONGO_URI)%"}]}' );

		\gcgov\framework\cli\appContext::$composerAutoloadPath = $this->tempRootDir . '/vendor/autoload.php';
	}


	protected function tearDown(): void {
		\gcgov\framework\cli\appContext::$composerAutoloadPath = null;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tempRootDir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->tempRootDir );
	}


	/**
	 * The documented bootstrap starts with `cp .env.example .env`, so by the time init runs
	 * the file already exists and carries the docker compose variables. init used to see it
	 * and skip the step entirely, which left the application's own variables — every one of
	 * them required — undeclared, and `gf env` then failed on the first.
	 */
	public function testInitAppendsToAnExistingEnvRatherThanSkippingIt(): void {
		file_put_contents( $this->tempRootDir . '/.env', "# compose\nHTTP_PORT=8080\n" );

		$this->runInit();

		$env = (string)file_get_contents( $this->tempRootDir . '/.env' );
		self::assertStringContainsString( 'HTTP_PORT=8080', $env, 'the compose half must survive' );
		self::assertMatchesRegularExpression( '/^APP_TYPE=/m', $env );
		self::assertMatchesRegularExpression( '/^MONGO_DATABASE=/m', $env );
		self::assertMatchesRegularExpression( '/^MONGO_URI=/m', $env );
	}


	public function testInitWritesTheEnvFileWhenThereIsNone(): void {
		$this->runInit();

		$env = (string)file_get_contents( $this->tempRootDir . '/.env' );
		self::assertMatchesRegularExpression( '/^APP_TYPE=/m', $env );
	}


	/** A value already filled in is never rewritten. */
	public function testInitLeavesFilledInValuesAlone(): void {
		file_put_contents( $this->tempRootDir . '/.env', "APP_TYPE=local\n" );

		$this->runInit();

		$env = (string)file_get_contents( $this->tempRootDir . '/.env' );
		self::assertStringContainsString( 'APP_TYPE=local', $env );
		self::assertSame( 1, preg_match_all( '/^APP_TYPE=/m', $env ), 'the variable must not be declared twice' );
	}


	/**
	 * Driven through a real Application because the .env step is `env --init` run as a
	 * sub-command, which a bare CommandTester cannot resolve.
	 */
	private function runInit(): void {
		$application = new \gcgov\framework\cli\application();
		$application->setAutoExit( false );
		$exitCode = $application->run(
			new \Symfony\Component\Console\Input\ArrayInput( [ 'command' => 'init', '--title' => 'Test API', '--skip-keys' => true, '--skip-chrome' => true ] ),
			new \Symfony\Component\Console\Output\NullOutput()
		);

		self::assertSame( 0, $exitCode );
	}


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
