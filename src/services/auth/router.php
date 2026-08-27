<?php

namespace gcgov\framework\services\auth;

use gcgov\framework\config;
use gcgov\framework\models\config\services\auth as authConfig;
use gcgov\framework\models\route;

/**
 * One authentication service, two providers.
 *
 * The routes below the provider split are the same either way: both providers issue
 * framework JWTs, so the public key document, the short-lived file token, and the guard
 * that validates a token on every authenticated route are shared. Only token acquisition
 * differs, which is what each provider contributes.
 */
class router implements \gcgov\framework\interfaces\router {

	private const SHARED   = '\gcgov\framework\services\auth\controllers\auth';
	private const OAUTH    = '\gcgov\framework\services\auth\providers\oauth\controllers\auth';
	private const MS_FRONT = '\gcgov\framework\services\auth\providers\msFront\controllers\auth';

	public function __construct( private readonly authConfig $config ) {
	}


	public function getRoutes(): array {
		$basePath = config::getBasePath();

		$routes = [
			new route( 'GET', $basePath . '/.well-known/jwks.json', self::SHARED, 'jwks', false, description: 'Public keys for validating tokens this application issued.' ),
			new route( 'GET', $basePath . '/auth/fileToken', self::SHARED, 'fileToken', true, description: 'Mint a short-lived token usable as ?fileAccessToken=.' ),
		];

		if( $this->config->isOauth() ) {
			return array_merge( $routes, [
				new route( 'GET', $basePath . '/.well-known/openid-configuration', self::OAUTH, 'openId', false ),
				new route( 'POST', $basePath . '/auth/authorize', self::OAUTH, 'oauthPostAuthorize', false ),
				new route( 'GET', $basePath . '/auth/authorize', self::OAUTH, 'oauthGetAuthorize', false ),
				new route( 'GET', $basePath . '/auth/hybridauth/{provider}', self::OAUTH, 'oauthHybridAuth', false ),
				new route( 'GET', $basePath . '/auth/out', self::OAUTH, 'out', true ),
				new route( 'POST', $basePath . '/auth/verifyMfaSecret', self::OAUTH, 'verifyMfaSecret', true ),
				new route( 'POST', $basePath . '/auth/verifyMfaCode', self::OAUTH, 'verifyMfaCode', true ),
			] );
		}

		return array_merge( $routes, [
			new route( 'GET', $basePath . '/auth/microsoft', self::MS_FRONT, 'microsoft', false ),
		] );
	}


	/**
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public function authentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
		return guard::authenticate( $routeHandler );
	}

}
