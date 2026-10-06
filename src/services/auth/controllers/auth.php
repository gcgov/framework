<?php

namespace gcgov\framework\services\auth\controllers;

use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;

/**
 * The endpoints that are the same whichever provider mints the tokens.
 *
 * Both providers issue framework JWTs signed by the same keys, so the public key
 * document and the short-lived file token are provider-independent. They were previously
 * implemented once per provider package.
 */
class auth implements controller {

	public function __construct() {
	}


	/**
	 * @OA\Get(
	 *     path="/.well-known/jwks.json",
	 *     tags={"Auth"},
	 *     description="Public keys for validating tokens this application issued"
	 * )
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 */
	public function jwks(): controllerDataResponse {
		$jwtService = new \gcgov\framework\services\jwtAuth\jwtAuth();

		return new controllerDataResponse( [
			'keys' => $jwtService->getJwksKeys()
		] );
	}


	/**
	 * @OA\Get(
	 *     path="/auth/fileToken",
	 *     tags={"Auth"},
	 *     description="Exchange your access token for a very short lived one that may be passed as a URL parameter on routes which allow it"
	 * )
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 */
	public function fileToken(): controllerDataResponse {
		$authUser = \gcgov\framework\services\request::getAuthUser();

		$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
		$accessToken = $jwtService->createAccessToken( $authUser, new \DateInterval( 'PT5S' ) );

		return new controllerDataResponse( [
			'accessToken' => $accessToken->toString()
		] );
	}


	public static function _after(): void {
	}


	public static function _before(): void {
	}

}
