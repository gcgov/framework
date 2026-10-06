<?php

namespace gcgov\framework\services\auth\providers\msFront\controllers;

use gcgov\framework\config;
use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;

class auth implements controller {

	public function __construct() {

	}



	/**
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function microsoft(): controllerDataResponse {

		if( !isset( $_SERVER[ 'HTTP_AUTHORIZATION' ] ) ) {
			throw new controllerException( 'Microsoft access token not provided in authorization header', 401 );
		}

		//authenticate user with Microsoft
		$microsoftConfig               = new \andrewsauder\microsoftServices\config();
		$microsoftConfig->clientId     = config::getMicrosoft()->clientId;
		$microsoftConfig->clientSecret = config::getMicrosoft()->clientSecret;
		$microsoftConfig->tenant       = config::getMicrosoft()->tenant;
		$microsoftConfig->fromAddress  = config::getMicrosoft()->fromAddress;
		$microsoftAuthService = new \andrewsauder\microsoftServices\auth( $microsoftConfig ); // \gcgov\framework\services\microsoft\auth();
		$tokenInfo            = $microsoftAuthService->verify();
		$user                 = $this->lookupUserMicrosoftTokenInfo( $tokenInfo );

		//convert \app\models\user to authUser singleton
		$authUser = \gcgov\framework\services\request::getAuthUser();
		$authUser->setFromUser( $user );

		//generate our custom jwt and return it to the user
		$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
		$accessToken = $jwtService->createAccessToken( $authUser );

		//return data
		$data = [
			'accessToken' => $accessToken->toString()
		];

		return new controllerDataResponse( $data );

	}



	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after(): void {

	}


	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before(): void {

	}


	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	private function lookupUserMicrosoftTokenInfo( \andrewsauder\microsoftServices\components\tokenInformation $tokenInfo ): \gcgov\framework\services\mongodb\models\auth\user {
		$userClassName = \gcgov\framework\services\request::getUserClassFqdn();

		//get user from database using Microsoft unique Id
		try {
			$authConfig = config::getServices()->auth;

			$user = $userClassName::getFromOauth(
				email:            $tokenInfo->email,
				externalId:       $tokenInfo->oid,
				externalProvider: 'MicrosoftGraph',
				firstName:        $tokenInfo->name,
				addIfNotExisting: !$authConfig->blockNewUsers,
				rolesForNewUser:  $authConfig->defaultNewUserRoles );
		}
		catch( modelException $e ) {
			throw new \gcgov\framework\exceptions\controllerException( 'The Microsoft user may need to be added to the user collection within the application. This Microsoft user could not be found in the app user list by external id and does not have a preferred username to lookup by email.', 404, $e );
		}

		try {
			$updateResult = $userClassName::save( $user );
		}
		catch( modelException $e ) {
			//failed to save external id - no problem, we will try again next sign in
		}

		return $user;
	}

}
