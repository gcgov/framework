<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\config;
use gcgov\framework\models\unifiedConfig;

#[CoversClass(config::class)]
final class ConfigTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		// Forward slashes, because that is the only shape config ever holds: setAppDir()
		// normalises the separators it reflects out of \app\app, and the gf CLI reaches the
		// same field through appContext::normalize(). Injecting sys_get_temp_dir() raw would
		// put a backslash root into a private static that cannot hold one at runtime, and the
		// accessors that normalise — getConfigFilePath(), getJwtKeyPath() — would then
		// disagree with the fixture on Windows and nowhere else.
		$this->tempRootDir = str_replace( '\\', '/', sys_get_temp_dir() ) . '/gcgov-config-test-' . uniqid();
		mkdir( $this->tempRootDir . '/app/config', 0777, true );

		$rootProp = new \ReflectionProperty( config::class, 'rootDir' );
		$rootProp->setValue( null, $this->tempRootDir );
		$appProp = new \ReflectionProperty( config::class, 'appDir' );
		$appProp->setValue( null, $this->tempRootDir . '/app' );
	}

	public function testGetAppDirReflectsConfiguredValue(): void {
		$this->assertSame( $this->tempRootDir . '/app', config::getAppDir() );
	}

	public function testGetModelsDirAppendsModels(): void {
		$this->assertSame( $this->tempRootDir . '/app/models/', config::getModelsDir() );
	}

	public function testGetConfigFilePathIsRootConfigJson(): void {
		$this->assertSame( $this->tempRootDir . '/config.json', config::getConfigFilePath() );
	}

	public function testGetServicesDirAppendsServices(): void {
		$this->assertSame( $this->tempRootDir . '/app/services/', config::getServicesDir() );
	}

	public function testGetSrvDirReturnsRootSrv(): void {
		$this->assertSame( $this->tempRootDir . '/srv/', config::getSrvDir() );
	}

	public function testGetRootDirReturnsConfiguredValue(): void {
		$this->assertSame( $this->tempRootDir, config::getRootDir() );
	}

	public function testGetTempDirIsRootSrvTmpTmp(): void {
		$this->assertSame( $this->tempRootDir . '/srv/tmp/tmp', config::getTempDir() );
	}

	public function testUnifiedConfigIsExposedThroughStaticAccessors(): void {
		$unified                 = new unifiedConfig();
		$unified->type           = 'local';
		$unified->basePath       = 'custom';
		$unified->rootUrl        = 'https://example.gov/';
		$unified->app->title     = 'Widget API';
		$unified->appDictionary  = [ 'key' => 'value' ];

		$prop = new \ReflectionProperty( config::class, 'unifiedConfig' );
		$prop->setValue( null, $unified );

		$this->assertSame( '/custom', config::getBasePath() );
		$this->assertSame( 'https://example.gov', config::getRootUrl() );
		$this->assertSame( 'https://example.gov/custom', config::getBaseUrl() );
		$this->assertTrue( config::isLocal() );
		$this->assertSame( 'Widget API', config::getApp()->title );
		$this->assertSame( [ 'key' => 'value' ], config::getAppDictionary() );
		$this->assertSame( $unified->logging, config::getLogging() );
		$this->assertSame( $unified->email, config::getEmail() );
		$this->assertSame( $unified->settings, config::getSettings() );
	}

	public function testDeprecatedPassThroughsPreserveV6CallPatterns(): void {
		$unified                                    = new unifiedConfig();
		$unified->type                              = 'prod';
		$unified->basePath                          = '/api/';
		$unified->settings->forceMfaForPasswordUsers = true;
		$unified->app->title                        = 'Widget API';

		$prop = new \ReflectionProperty( config::class, 'unifiedConfig' );
		$prop->setValue( null, $unified );

		// v6 environmentConfig call patterns — the shim returns the unified object
		$this->assertSame( $unified, config::getEnvironmentConfig() );
		$this->assertSame( '/api', config::getEnvironmentConfig()->getBasePath() );
		$this->assertFalse( config::getEnvironmentConfig()->isLocal() );
		$this->assertSame( [], config::getEnvironmentConfig()->mongoDatabases );

		// v6 appConfig call patterns — the shim returns a v6-shaped VIEW (app/email/settings)
		$appConfig = config::getAppConfig();
		$this->assertInstanceOf( \gcgov\framework\models\appConfig::class, $appConfig );
		$this->assertTrue( $appConfig->settings->forceMfaForPasswordUsers );
		$this->assertSame( 'Widget API', $appConfig->app->title );
		$this->assertSame( '', $appConfig->email->SMTPUsername );
		$this->assertSame( $unified->settings, $appConfig->settings, 'view shares the live section objects' );

		// The view must NOT expose environment-side secrets (mongo/microsoft/payjunction/jwtAuth)
		$viewProperties = array_keys( get_object_vars( $appConfig ) );
		$this->assertSame( [ 'app', 'email', 'settings' ], $viewProperties );
	}


	public function testEnvironmentConfigClassAliasResolvesToUnifiedConfig(): void {
		// v6 type references (\gcgov\framework\models\environmentConfig) must still autoload.
		$this->assertTrue( class_exists( \gcgov\framework\models\environmentConfig::class ) );
		$this->assertSame( unifiedConfig::class, ( new \ReflectionClass( \gcgov\framework\models\environmentConfig::class ) )->getName() );
		$this->assertInstanceOf( \gcgov\framework\models\environmentConfig::class, new unifiedConfig() );
	}

	public function testIsFinalClass(): void {
		$this->assertTrue( ( new \ReflectionClass( config::class ) )->isFinal() );
	}


	/**
	 * The keys are gitignored, so they are never in a built image and must be provisioned
	 * to a path outside the application tree. Before v7 the path was hard-coded.
	 */
	public function testJwtKeyPathDefaultsToSrvButHonoursTheConfiguredPath(): void {
		$unified = config::getEnvironmentConfig();
		$original = $unified->jwtAuth->keyPath;

		try {
			$unified->jwtAuth->keyPath = '';
			$this->assertSame( config::getSrvDir() . 'jwtCertificates/', config::getJwtKeyPath() );

			$unified->jwtAuth->keyPath = '/run/secrets/jwt';
			$this->assertSame( '/run/secrets/jwt/', config::getJwtKeyPath(), 'always returned with a trailing slash' );

			$unified->jwtAuth->keyPath = '/run/secrets/jwt/';
			$this->assertSame( '/run/secrets/jwt/', config::getJwtKeyPath(), 'a configured trailing slash is not doubled' );
		}
		finally {
			$unified->jwtAuth->keyPath = $original;
		}
	}


	/** Configuring issuer and audience separately from rootUrl/basePath only invites drift. */
	public function testJwtIssuerAndAudienceDeriveFromTheApplicationUrlWhenNotSet(): void {
		$unified = config::getEnvironmentConfig();
		$originalIssuer   = $unified->jwtAuth->tokenIssuedBy;
		$originalAudience = $unified->jwtAuth->tokenPermittedFor;

		try {
			$unified->jwtAuth->tokenIssuedBy     = '';
			$unified->jwtAuth->tokenPermittedFor = '';
			$this->assertSame( config::getRootUrl(), config::getTokenIssuedBy() );
			$this->assertSame( config::getBasePath(), config::getTokenPermittedFor() );

			$unified->jwtAuth->tokenIssuedBy = 'https://explicit.example.gov';
			$this->assertSame( 'https://explicit.example.gov', config::getTokenIssuedBy() );
		}
		finally {
			$unified->jwtAuth->tokenIssuedBy     = $originalIssuer;
			$unified->jwtAuth->tokenPermittedFor = $originalAudience;
		}
	}


	/** @return iterable<string, array{0: string}> */
	public static function removedAccessorProvider(): iterable {
		yield 'getServerName' => [ 'getServerName' ];
		yield 'getCookieUrl' => [ 'getCookieUrl' ];
		yield 'getPhpPath' => [ 'getPhpPath' ];
	}


	/**
	 * Confirmed unread by the framework and by all five framework services before removal.
	 *
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('removedAccessorProvider')]
	public function testAccessorsForUnreadConfigValuesAreGone( string $accessor ): void {
		$this->assertFalse( method_exists( config::class, $accessor ) );
	}


	public function testUnreadConfigPropertiesAreGoneFromTheModel(): void {
		$properties = array_keys( get_object_vars( new unifiedConfig() ) );

		foreach( [ 'serverName', 'cookieUrl', 'phpPath' ] as $removed ) {
			$this->assertNotContains( $removed, $properties );
		}
		$this->assertContains( 'app', $properties, 'app.guid stays — the oauth server uses it as the client_id' );
	}

}
