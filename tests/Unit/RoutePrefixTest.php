<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit;

use gcgov\framework\config;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\tests\Support\seedsFrameworkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The domain-root case: config.json with `"basePath": ""`, which is both the default and a
 * documented deployment.
 *
 * getBasePath() returns '/' there — correct for the token audience, which is its other
 * caller — so a router that concatenated it with a leading-slash pattern registered
 * '//user' and '//auth/authorize'. FastRoute stores a placeholder-free pattern as a literal
 * static key and matches it with an exact string comparison, so those routes could never be
 * hit: every Framework Service endpoint 404'd while /health worked, because the health
 * router alone happened to rtrim.
 *
 * Every existing router test seeds a non-empty base path ('api', 'api/v1', 'custom'), which
 * is exactly why nothing caught it.
 */
#[CoversClass(unifiedConfig::class)]
#[CoversClass(config::class)]
final class RoutePrefixTest extends TestCase {

	use seedsFrameworkConfig;

	public function testRoutePrefixIsEmptyAtDomainRoot(): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = '' );

		$this->assertSame( '/', config::getBasePath(), 'getBasePath() keeps its "/" for the token audience' );
		$this->assertSame( '', config::getRoutePrefix(), 'a route prefix must contribute nothing at the domain root' );
	}


	public function testRoutePrefixIsNormalisedBasePathOtherwise(): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = 'api/v1' );

		$this->assertSame( '/api/v1', config::getRoutePrefix() );
	}


	#[DataProvider('untidyBasePaths')]
	public function testRoutePrefixToleratesUntidyConfiguredValues( string $configured, string $expected ): void {
		$this->seedConfig( static fn( unifiedConfig $c ) => $c->basePath = $configured );

		$this->assertSame( $expected, config::getRoutePrefix() );
	}


	/** @return array<string, array{string, string}> */
	public static function untidyBasePaths(): array {
		return [
			'leading slash'  => [ '/api', '/api' ],
			'trailing slash' => [ 'api/', '/api' ],
			'both'           => [ '/api/', '/api' ],
			'whitespace'     => [ '  api  ', '/api' ],
			'only a slash'   => [ '/', '' ],
			'only spaces'    => [ '   ', '' ],
		];
	}


	/**
	 * The regression itself: no framework route may contain '//' at the domain root.
	 */
	public function testNoFrameworkRouteDoublesItsSlashesAtDomainRoot(): void {
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$c->basePath = '';
			$c->rootUrl  = 'https://example.gov';
		} );

		$routers = [
			'health'        => new \gcgov\framework\services\health\router(),
			'userCrud'      => new \gcgov\framework\services\userCrud\router(),
			'documentation' => new \gcgov\framework\services\documentation\router(),
			'auth'          => new \gcgov\framework\services\auth\router( $this->oauthAuthConfig() ),
		];

		foreach( $routers as $name => $router ) {
			foreach( $router->getRoutes() as $route ) {
				$this->assertStringStartsWith( '/', $route->route, $name . ' route must be rooted' );
				$this->assertStringNotContainsString( '//', $route->route, $name . ' route "' . $route->route . '" would never match a request' );
			}
		}
	}


	public function testFrameworkRoutesCarryTheBasePathWhenThereIsOne(): void {
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$c->basePath = 'api';
			$c->rootUrl  = 'https://example.gov';
		} );

		$routes = ( new \gcgov\framework\services\userCrud\router() )->getRoutes();

		$this->assertSame( '/api/user', $routes[ 0 ]->route );
	}


	/**
	 * getBaseUrl() has the same shape of defect: it is concatenated with '/auth/authorize'
	 * to build the advertised openid-configuration and the OAuth callback, so a trailing
	 * slash at the domain root produced 'https://host//auth/hybridauth/...' — which fails
	 * redirect-URI matching at the provider.
	 */
	public function testBaseUrlHasNoTrailingSlashAtDomainRoot(): void {
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$c->basePath = '';
			$c->rootUrl  = 'https://example.gov';
		} );

		$this->assertSame( 'https://example.gov', config::getBaseUrl() );
		$this->assertStringNotContainsString( '//auth', config::getBaseUrl() . '/auth/authorize' );
	}


	public function testBaseUrlStillJoinsRootUrlAndBasePath(): void {
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$c->basePath = 'api/v1';
			$c->rootUrl  = 'https://example.gov/';
		} );

		$this->assertSame( 'https://example.gov/api/v1', config::getBaseUrl() );
	}


	private function oauthAuthConfig(): \gcgov\framework\models\config\services\auth {
		$auth           = new \gcgov\framework\models\config\services\auth();
		$auth->provider = \gcgov\framework\models\config\services\auth::PROVIDER_OAUTH;

		return $auth;
	}

}
