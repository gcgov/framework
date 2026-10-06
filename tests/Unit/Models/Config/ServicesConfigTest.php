<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models\Config;

use gcgov\framework\models\config\services;
use gcgov\framework\models\config\services\auth;
use gcgov\framework\models\config\services\documentation;
use gcgov\framework\models\config\services\userCrud;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\services\environment\environmentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Activation is presence: a service whose block is absent is off, a block that is present
 * — even empty — is on. These tests pin that, and the fail-closed rules that stop an
 * auth block describing a provider nothing will read.
 */
#[CoversClass( services::class )]
#[CoversClass( auth::class )]
final class ServicesConfigTest extends TestCase {

	private static function hydrate( string $json ): unifiedConfig {
		return unifiedConfig::jsonDeserialize( json_decode( $json, false ) );
	}


	public function testServicesSectionAbsentLeavesEveryServiceOff(): void {
		$config = self::hydrate( '{"type":"local"}' );

		$this->assertInstanceOf( services::class, $config->services );
		$this->assertNull( $config->services->auth );
		$this->assertNull( $config->services->userCrud );
		$this->assertNull( $config->services->documentation );
	}


	public function testEmptyServicesSectionLeavesEveryServiceOff(): void {
		$config = self::hydrate( '{"services":{}}' );

		$this->assertNull( $config->services->auth );
		$this->assertNull( $config->services->userCrud );
		$this->assertNull( $config->services->documentation );
	}


	public function testAnEmptyBlockEnablesTheService(): void {
		$config = self::hydrate( '{"services":{"userCrud":{}}}' );

		$this->assertInstanceOf( userCrud::class, $config->services->userCrud );
		$this->assertNull( $config->services->documentation );
		$this->assertNull( $config->services->auth );
	}


	public function testSeveralServicesEnableIndependently(): void {
		$config = self::hydrate( '{"services":{"userCrud":{},"documentation":{}}}' );

		$this->assertInstanceOf( userCrud::class, $config->services->userCrud );
		$this->assertInstanceOf( documentation::class, $config->services->documentation );
		$this->assertNull( $config->services->auth );
	}


	public function testAuthSettingsHydrate(): void {
		$config = self::hydrate( '{"services":{"auth":{"provider":"oauth","blockNewUsers":false,"defaultNewUserRoles":["Widget.Read"],"oauth":{"authorizeUrlParameters":{"prompt":"consent"}}}}}' );

		$auth = $config->services->auth;
		$this->assertInstanceOf( auth::class, $auth );
		$this->assertTrue( $auth->isOauth() );
		$this->assertFalse( $auth->blockNewUsers );
		$this->assertSame( [ 'Widget.Read' ], $auth->defaultNewUserRoles );
		$this->assertSame( [ 'prompt' => 'consent' ], $auth->oauth->authorizeUrlParameters );
		$this->assertNull( $auth->msFront );
	}


	public function testBlockNewUsersDefaultsToBlocking(): void {
		$config = self::hydrate( '{"services":{"auth":{"provider":"msFront"}}}' );

		$this->assertTrue( $config->services->auth->blockNewUsers );
	}


	/** A missing block for the selected provider is the established missing-section rule. */
	public function testSelectedProviderBlockMayBeOmitted(): void {
		$config = self::hydrate( '{"services":{"auth":{"provider":"oauth"}}}' );

		$this->assertInstanceOf( \gcgov\framework\models\config\services\auth\oauth::class, $config->services->auth->oauth );
		$this->assertSame( [], $config->services->auth->oauth->authorizeUrlParameters );
		$this->assertNull( $config->services->auth->msFront );
	}


	public function testMsFrontProviderSelects(): void {
		$config = self::hydrate( '{"services":{"auth":{"provider":"msFront"}}}' );

		$this->assertTrue( $config->services->auth->isMsFront() );
		$this->assertFalse( $config->services->auth->isOauth() );
		$this->assertInstanceOf( \gcgov\framework\models\config\services\auth\msFront::class, $config->services->auth->msFront );
	}


	/** Configuration that nothing would read is an error, not a silent no-op. */
	public function testBlockForTheUnselectedProviderIsRejected(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessage( 'services.auth.msFront is configured but services.auth.provider is "oauth"' );

		self::hydrate( '{"services":{"auth":{"provider":"oauth","msFront":{}}}}' );
	}


	public function testBlockForTheUnselectedProviderIsRejectedTheOtherWayRound(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessage( 'services.auth.oauth is configured but services.auth.provider is "msFront"' );

		self::hydrate( '{"services":{"auth":{"provider":"msFront","oauth":{}}}}' );
	}


	public function testUnknownProviderIsRejected(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessage( 'not "typo"' );

		self::hydrate( '{"services":{"auth":{"provider":"typo"}}}' );
	}


	public function testMissingProviderIsRejected(): void {
		$this->expectException( environmentException::class );
		$this->expectExceptionMessage( 'is missing' );

		self::hydrate( '{"services":{"auth":{"blockNewUsers":false}}}' );
	}


	public function testProviderConstantsAreTheLegalValues(): void {
		$this->assertSame( [ 'oauth', 'msFront' ], auth::PROVIDERS );
	}

}
