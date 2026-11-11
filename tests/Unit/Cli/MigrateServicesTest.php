<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\migrateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Migrating a v6 application's Framework Services means reading which ones it registered
 * out of PHP and writing them into config.json.
 */
#[CoversClass( migrateCommand::class )]
final class MigrateServicesTest extends TestCase {

	/**
	 * The scaffolded app.php ships the alternatives commented out directly above the live
	 * array. Reporting those would enable services the application does not run — and, for
	 * the auth pair, would report a conflict that is not there.
	 */
	private const TEMPLATE_APP_PHP = <<<'PHP'
	<?php
	namespace app;

	final class app implements \gcgov\framework\interfaces\app {
		public static function _after() : void {}
		public static function _before() : void {}

		public function registerFrameworkServiceNamespaces(): array {
			//uncomment to auto create new user entries if the user does not have one in the user collection
			//$msAuthConfig = \gcgov\framework\services\authmsfront\msAuthConfig::getInstance();
			//$msAuthConfig->setBlockNewUsers( false, constants::DEFAULT_ROLES );
			//$oauthConfig = \gcgov\framework\services\authoauth\oauthConfig::getInstance();
			//$oauthConfig->setBlockNewUsers( false, constants::DEFAULT_ROLES );
			return [
				'\gcgov\framework\services\documentation',
				'\gcgov\framework\services\cronMonitor',
				'\gcgov\framework\services\usercrud',
				//'\gcgov\framework\services\authmsfront',
				'\gcgov\framework\services\authoauth',
			];
		}
	}
	PHP;

	public function testCommentedOutRegistrationsAreNotDetected(): void {
		$detected = migrateCommand::detectServices( self::TEMPLATE_APP_PHP );

		$this->assertContains( 'documentation', $detected[ 'services' ] );
		$this->assertContains( 'userCrud', $detected[ 'services' ] );
		$this->assertContains( 'cronMonitor', $detected[ 'services' ] );
		$this->assertContains( 'auth:oauth', $detected[ 'services' ] );
		$this->assertNotContains( 'auth:msFront', $detected[ 'services' ], 'the msFront entry is commented out' );
	}


	public function testCommentedOutSingletonCallsAreNotDetected(): void {
		$detected = migrateCommand::detectServices( self::TEMPLATE_APP_PHP );

		$this->assertSame( [], $detected[ 'singletons' ] );
	}


	public function testLiveSingletonCallsAreDetected(): void {
		$source = '<?php namespace app; class app { public static function _before(): void {
			$c = \gcgov\framework\services\authoauth\oauthConfig::getInstance();
			$c->setBlockNewUsers( false, [ "Widget.Read" ] );
			$c->setAuthorizeUrlParameters( [ "prompt" => "consent" ] );
		} }';

		$detected = migrateCommand::detectServices( $source );

		$this->assertContains( 'setBlockNewUsers', $detected[ 'singletons' ] );
		$this->assertContains( 'setAuthorizeUrlParameters', $detected[ 'singletons' ] );
	}


	public function testDetectedServicesBecomeAConfigSection(): void {
		$detected = migrateCommand::detectServices( self::TEMPLATE_APP_PHP );
		$plan     = migrateCommand::plan( [], [], $detected );

		$services = $plan[ 'config' ][ 'services' ];
		$this->assertSame( [ 'provider' => 'oauth' ], $services[ 'auth' ] );
		$this->assertEquals( new \stdClass(), $services[ 'userCrud' ] );
		$this->assertEquals( new \stdClass(), $services[ 'documentation' ] );
		// cronMonitor is not a Framework Service any more, so it gets no services entry
		$this->assertArrayNotHasKey( 'cronMonitor', $services );
	}


	/** An empty services block must not appear at all rather than appear empty. */
	public function testNoDetectedServicesWritesNoServicesSection(): void {
		$plan = migrateCommand::plan( [], [], [ 'services' => [], 'singletons' => [] ] );

		$this->assertArrayNotHasKey( 'services', $plan[ 'config' ] );
	}


	public function testRegisteringBothAuthServicesKeepsOneAndWarns(): void {
		$plan = migrateCommand::plan( [], [], [ 'services' => [ 'auth:oauth', 'auth:msFront' ], 'singletons' => [] ] );

		$this->assertSame( [ 'provider' => 'oauth' ], $plan[ 'config' ][ 'services' ][ 'auth' ] );
		$this->assertNotEmpty( array_filter( $plan[ 'warnings' ], fn( string $w ): bool => str_contains( $w, 'Both authentication services were registered' ) ) );
	}


	public function testCronMonitorUrlMovesOutOfAppDictionary(): void {
		$plan = migrateCommand::plan( [], [ 'appDictionary' => [ 'cronMonitorUrl' => 'https://monitor.local/', 'other' => 'kept' ] ] );

		$this->assertSame( [ 'url' => 'https://monitor.local/' ], $plan[ 'config' ][ 'cronMonitor' ] );
		$this->assertSame( [ 'other' => 'kept' ], $plan[ 'config' ][ 'appDictionary' ] );
	}


	public function testSingletonCallsBecomeWarningsNamingTheirReplacement(): void {
		$plan = migrateCommand::plan( [], [], [ 'services' => [], 'singletons' => [ 'setBlockNewUsers' ] ] );

		$matching = array_filter( $plan[ 'warnings' ], fn( string $w ): bool => str_contains( $w, 'setBlockNewUsers' ) );
		$this->assertNotEmpty( $matching );
		$this->assertStringContainsString( 'services.auth.blockNewUsers', implode( ' ', $matching ) );
	}


	public function testServiceRequiresAreRemovedFromComposerJson(): void {
		$result = migrateCommand::removeServiceRequires( [
			'require' => [
				'php'                                        => '>=8.4',
				'gcgov/framework'                            => '^7.0',
				'gcgov/framework-service-documentation'      => '^1.1',
				'gcgov/framework-service-user-crud'          => '^1.1',
				'gcgov/framework-service-auth-oauth-server'  => '^2.1',
			],
			'require-dev' => [ 'phpunit/phpunit' => '^11.5' ],
		] );

		$this->assertSame( [ 'php' => '>=8.4', 'gcgov/framework' => '^7.0' ], $result[ 'json' ][ 'require' ] );
		$this->assertSame( [ 'phpunit/phpunit' => '^11.5' ], $result[ 'json' ][ 'require-dev' ] );
		$this->assertCount( 3, $result[ 'removed' ] );
	}


	public function testComposerJsonWithoutServicePackagesIsUnchanged(): void {
		$input  = [ 'require' => [ 'php' => '>=8.4', 'gcgov/framework' => '^7.0' ] ];
		$result = migrateCommand::removeServiceRequires( $input );

		$this->assertSame( $input, $result[ 'json' ] );
		$this->assertSame( [], $result[ 'removed' ] );
	}

}
