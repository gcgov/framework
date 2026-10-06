<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\config;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Framework Services are constructed from config.json, so "is this service on?" is
 * answered by the same file that configures it. These tests drive the real router
 * against real configuration rather than asserting on a registry.
 */
#[CoversClass( router::class )]
final class RouterServiceActivationTest extends TestCase {

	private static unifiedConfig $original;

	public static function setUpBeforeClass(): void {
		self::$original = ( new \ReflectionProperty( config::class, 'unifiedConfig' ) )->getValue();
	}


	protected function tearDown(): void {
		( new \ReflectionProperty( config::class, 'unifiedConfig' ) )->setValue( null, self::$original );
	}


	private static function useServices( string $servicesJson ): void {
		$config = unifiedConfig::jsonDeserialize( json_decode( '{"type":"local","rootUrl":"http://test.local","basePath":"api","services":' . $servicesJson . '}', false ) );
		( new \ReflectionProperty( config::class, 'unifiedConfig' ) )->setValue( null, $config );
	}


	/** @return string[] */
	private static function routePaths(): array {
		$paths = [];
		foreach( router::getMergedRoutes() as $route ) {
			$paths[] = $route->route;
		}

		return $paths;
	}


	public function testNoServicesGivesOnlyHealthAndAppRoutes(): void {
		self::useServices( '{}' );
		$paths = self::routePaths();

		$this->assertContains( '/api/health', $paths );
		$this->assertContains( '/api/health/ready', $paths );
		$this->assertContains( '/widget', $paths );
		$this->assertNotContains( '/api/user', $paths );
		$this->assertNotContains( '/api/documentation.yaml', $paths );
	}


	public function testUserCrudBlockAddsItsRoutes(): void {
		self::useServices( '{"userCrud":{}}' );
		$paths = self::routePaths();

		$this->assertContains( '/api/user', $paths );
		$this->assertContains( '/api/user/{_id}', $paths );
		$this->assertNotContains( '/api/documentation.yaml', $paths );
	}


	public function testDocumentationBlockAddsItsRoute(): void {
		self::useServices( '{"documentation":{}}' );

		$this->assertContains( '/api/documentation.yaml', self::routePaths() );
	}


	/**
	 * The provider decides which token-acquisition routes exist. The shared ones —
	 * jwks and fileToken — are present either way.
	 */
	public function testOauthProviderContributesTheOauthRoutes(): void {
		self::useServices( '{"auth":{"provider":"oauth"}}' );
		$paths = self::routePaths();

		$this->assertContains( '/api/.well-known/jwks.json', $paths );
		$this->assertContains( '/api/auth/fileToken', $paths );
		$this->assertContains( '/api/auth/authorize', $paths );
		$this->assertContains( '/api/auth/hybridauth/{provider}', $paths );
		$this->assertContains( '/api/auth/verifyMfaCode', $paths );
		$this->assertContains( '/api/.well-known/openid-configuration', $paths );
		$this->assertNotContains( '/api/auth/microsoft', $paths );
	}


	public function testMsFrontProviderContributesOnlyTheExchangeRoute(): void {
		self::useServices( '{"auth":{"provider":"msFront"}}' );
		$paths = self::routePaths();

		$this->assertContains( '/api/.well-known/jwks.json', $paths );
		$this->assertContains( '/api/auth/fileToken', $paths );
		$this->assertContains( '/api/auth/microsoft', $paths );
		$this->assertNotContains( '/api/auth/authorize', $paths );
		$this->assertNotContains( '/api/auth/verifyMfaCode', $paths );
	}


	/** Every route the enabled providers register must point at a real method. */
	public function testEveryRouteResolvesToAnExistingControllerMethod(): void {
		foreach( [ '{"auth":{"provider":"oauth"},"userCrud":{},"documentation":{}}', '{"auth":{"provider":"msFront"}}' ] as $services ) {
			self::useServices( $services );
			foreach( router::getMergedRoutes() as $route ) {
				if( !str_starts_with( ltrim( $route->class, '\\' ), 'gcgov\\framework\\services' ) ) {
					continue;
				}
				$this->assertTrue( class_exists( $route->class ), 'missing controller ' . $route->class );
				$this->assertTrue( method_exists( $route->class, $route->method ), $route->class . '::' . $route->method . '() does not exist' );
			}
		}
	}


	public function testEnablingEverythingProducesNoDuplicateRoutes(): void {
		self::useServices( '{"auth":{"provider":"oauth"},"userCrud":{},"documentation":{}}' );

		$seen = [];
		foreach( router::getMergedRoutes() as $route ) {
			foreach( (array)$route->httpMethod as $method ) {
				$key = $method . ' ' . $route->route;
				$this->assertNotContains( $key, $seen, 'duplicate route ' . $key . ' would be a FastRoute boot failure' );
				$seen[] = $key;
			}
		}
	}

}
