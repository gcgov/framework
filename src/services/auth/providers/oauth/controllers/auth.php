<?php

namespace gcgov\framework\services\auth\providers\oauth\controllers;

use andrewsauder\jsonDeserialize\exceptions\jsonDeserializeException;
use gcgov\framework\config;
use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\services\auth\providers\oauth\models\stdAuthResponse;
use gcgov\framework\services\auth\providers\oauth\models\verifyMfaCodeRequest;
use gcgov\framework\services\auth\providers\oauth\models\verifyMfaSecretRequest;
use gcgov\framework\services\auth\providers\oauth\services\multifactor;
use gcgov\framework\services\formatting;
use gcgov\framework\services\log;

/**
 * @OA\Tag(
 *     name="Auth",
 *     description="Standard auth access token/refresh response"
 * )
 */
class auth implements controller {

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


	public function __construct() {
	}



	/**
	 * @OA\Get(
	 *     path="/.well-known/openid-configuration",
	 *     tags={"Auth"},
	 *     description="Oauth openid-configuration"
	 * )
	 * @return \gcgov\framework\models\controllerDataResponse
	 */
	public function openId(): controllerDataResponse {
		$baseUrl = config::getBaseUrl();

		$data = [
			"issuer"                                => $baseUrl,
			"authorization_endpoint"                => $baseUrl . "/auth/authorize",
			"token_endpoint"                        => $baseUrl . "/auth/authorize",
			//"userinfo_endpoint"                     => "https://example.com/userinfo",
			"jwks_uri"                              => $baseUrl . "/.well-known/jwks.json",
			"end_session_endpoint"                  => $baseUrl . "/auth/out",
			"scopes_supported"                      => [
				"login"
			],
			"response_types_supported"              => [
				"code",
				"token"
			],
			"token_endpoint_auth_methods_supported" => [
				"client_secret_post",
				"private_key_jwt",
			],

		];

		//custom result for /.well-known/jwks.json
		return new controllerDataResponse( $data );
	}



	/**
	 * @OA\Get(
	 *     path="/auth/authorize",
	 *     tags={"Auth"},
	 *     description="Direct access by end user - will send through to selected third party Oauth provider",
	 *     @OA\Parameter(
	 *         name="response_type",
	 *         in="query",
	 *         description="Only supported value is 'code'",
	 *         required=true,
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="client_id",
	 *         in="query",
	 *         description="Must match app config guid",
	 *         required=true,
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="scope",
	 *         in="query",
	 *         description="Must be oauth provider name string (ex. microsoft)",
	 *         required=true,
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Response(
	 *      response="200",
	 *      description="Successfully fetched",
	 *      @OA\JsonContent(
	 *          type="array",
	 *          @OA\Items(ref="#/components/schemas/stdAuthResponse")
	 *      )
	 *    )
	 * )
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function oauthGetAuthorize(): controllerDataResponse {
		if( empty( $_GET[ 'response_type' ] ) || $_GET[ 'response_type' ]!='code' ) {
			throw new controllerException( 'Invalid response type', 401 );
		}
		if( empty( $_GET[ 'client_id' ] ) || $_GET[ 'client_id' ]!=config::getApp()->guid ) {
			throw new controllerException( 'Invalid client id', 401 );
		}
		if( empty( $_GET[ 'scope' ] ) ) {
			throw new controllerException( 'Invalid scope', 401 );
		}

		if( session_status()!=PHP_SESSION_ACTIVE ) {
			session_start();
		}
		unset( $_SESSION[ 'auth_state' ] );
		if( !empty( $_GET[ 'state' ] ) ) {
			$_SESSION[ 'auth_state' ] = urldecode( $_GET[ 'state' ] );
		}

		$this->oauthHybridAuth( $_GET[ 'scope' ] );

		return new controllerDataResponse();
	}


	/**
	 * Handler for third party oauth provider (authorization_code), exchange
	 * refresh tokens (refresh_token), and exchange username/password
	 * (password). OpenAPI documentation for this endpoint is provided in the
	 * stdAuthResponse schema and the README; the inline @OA annotations were
	 * removed because the malformed nested structure broke phpDoc parsing.
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function oauthPostAuthorize(): controllerDataResponse {
		$postData = \gcgov\framework\services\request::getPostData();

		if( empty( $postData[ 'grant_type' ] ) ) {
			throw new controllerException( 'Invalid grant type', 401 );
		}
		if( empty( $postData[ 'client_id' ] ) || $postData[ 'client_id' ]!=config::getApp()->guid ) {
			throw new controllerException( 'Invalid client id', 401 );
		}

		//route
		if( $postData[ 'grant_type' ]=='password' ) {
			return new controllerDataResponse( $this->password() );
		}
		elseif( $postData[ 'grant_type' ]=='refresh_token' ) {
			return new controllerDataResponse( $this->refresh_token() );
		}
		elseif( $postData[ 'grant_type' ]=='authorization_code' ) {
			return new controllerDataResponse( $this->authorization_code() );
		}

		throw new controllerException( 'Invalid grant type', 401 );
	}


	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	private function password(): stdAuthResponse {
		$postData = \gcgov\framework\services\request::getPostData();

		if( empty( $postData[ 'scope' ] ) || $postData[ 'scope' ]!='login' ) {
			throw new controllerException( 'Invalid scope', 401 );
		}
		elseif( empty( $postData[ 'username' ] ) ) {
			throw new controllerException( 'Username required', 401 );
		}
		elseif( empty( $postData[ 'password' ] ) ) {
			throw new controllerException( 'Password required', 401 );
		}

		//authenticate the username and password combo
		try {
			$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
			/** @var \gcgov\framework\services\mongodb\models\auth\user $user */
			$user = $userClassName::verifyUsernamePassword( $postData[ 'username' ], $postData[ 'password' ] );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Incorrect username or password', 401, $e );
		}

		$authUser = \gcgov\framework\services\request::getAuthUser();

		//force configuration of MFA if required
		if( $user->mfaRequired ) {

			//lock the user roles down but give them an authentication token so that they can verify their MFA
			$user->roles = [];
			$authUser->setFromUser( $user );

			$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
			$accessToken = $jwtService->createAccessToken( $authUser );

			if( !$user->mfaConfigured ) {
				return multifactor::configureMfaResponse( $user->_id, $accessToken );
			}
			else  {
				return multifactor::requireMfaResponse( $accessToken, $user );
			}
		}


		//create token for valid user
		return $this->createAccessTokenResponse( $user );
	}


	/**
	 * @OA\Get(
	 *     path="/auth/out",
	 *     tags={"Auth"},
	 *     description="Sign out",
	 *     @OA\Response(
	 *      response="201",
	 *      description="Successfully fetched",
	 *    )
	 * )
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 */
	public function out(): controllerDataResponse {
		//unset the session variables
		if( isset( $_SESSION ) ) {
			foreach( $_SESSION as $key => $value ) {
				unset( $_SESSION[ $key ] );
			}
		}

		//delete the session cookie
		$params = session_get_cookie_params();
		setcookie( session_name(),
		           '',
		           time() - 42000,
		           $params[ "path" ],
		           $params[ "domain" ],
		           $params[ "secure" ],
		           $params[ "httponly" ] );

		//destroy the session
		if( session_status()==PHP_SESSION_ACTIVE ) {
			session_destroy();
		}

		//delete all other cookies
		$past = time() - 3600;
		foreach( $_COOKIE as $key => $value ) {
			setcookie( $key, $value, $past, '/' );
		}

		//TODO: burn refresh token

		return new controllerDataResponse( [] );
	}


	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	private function refresh_token(): stdAuthResponse {
		$postData = \gcgov\framework\services\request::getPostData();

		if( empty( $postData[ 'refresh_token' ] ) ) {
			throw new controllerException( 'Invalid refresh token', 401 );
		}

		try {
			\gcgov\framework\services\jwtAuth\models\userRefreshToken::removeOutdatedRefreshTokens();
		}
		catch( modelException $e ) {
			throw new controllerException( 'Failed to remove outdated refresh token', 500 );
		}

		$jwtValidationService = new \gcgov\framework\services\jwtAuth\jwtAuth();

		try {
			$userId = $jwtValidationService->validateRefreshToken( $postData[ 'refresh_token' ] );
		}
		catch( \Exception $e ) {
			throw new controllerException( $e->getMessage(), 401, $e );
		}

		//invalidate the existing token because we will provide a new one with the response
		try {
			$jwtValidationService->deleteRefreshToken( $postData[ 'refresh_token' ] );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Failed to remove existing refresh token', 500 );
		}

		try {
			$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
			/** @var \gcgov\framework\services\mongodb\models\auth\user $user */
			$user = $userClassName::getOne( $userId );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Refresh token corrupted', 401 );
		}

		//create token for good user
		$authUser = \gcgov\framework\services\request::getAuthUser();
		$authUser->setFromUser( $user );

		$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
		$accessToken = $jwtService->createAccessToken( $authUser );
		try {
			$refreshToken = $jwtService->createRefreshToken( $authUser );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Failed to create new refresh token', 500 );
		}

		return new stdAuthResponse( $accessToken, $refreshToken );

	}


	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	private function authorization_code(): stdAuthResponse {
		$postData = \gcgov\framework\services\request::getPostData();

		if( empty( $postData[ 'code' ] ) ) {
			throw new controllerException( 'Invalid code', 401 );
		}

		//lookup auth code
		try {
			$userAuthorizationCode = \gcgov\framework\services\jwtAuth\models\userAuthorizationCode::getOne( $postData[ 'code' ] );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Invalid code', 401 );
		}

		try {
			$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
			/** @var \gcgov\framework\interfaces\auth\user $user */
			$user = $userClassName::getOne( $userAuthorizationCode->userId );
		}
		catch( modelException $e ) {
			throw new controllerException( 'Authorization code corrupted', 401 );
		}

		//create token for good user
		$authUser = \gcgov\framework\services\request::getAuthUser();
		$authUser->setFromUser( $user );

		$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
		$accessToken = $jwtService->createAccessToken( $authUser );
		try {
			$refreshToken = $jwtService->createRefreshToken( $authUser );
		}
		catch( modelException $e ) {
			throw new controllerException( $e->getMessage(), 500, $e );
		}

		return new stdAuthResponse( $accessToken, $refreshToken );
	}

	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function oauthHybridAuth( string $provider = '' ): void {
		$provider = strtolower( $provider );

		if( $provider=='google' ) {
			$provider = "Google";
		}
		elseif( $provider=='facebook' ) {
			$provider = "Facebook";
		}
		elseif( $provider=='microsoft' || $provider=='microsoftgraph' ) {
			$provider = "MicrosoftGraph";

			if( empty( config::getMicrosoft()->clientId ) ) {
				throw new controllerException( 'Microsoft client id has not been defined in the app config file. /app/config/environment.json > microsoft.clientId', 400 );
			}
			if( empty( config::getMicrosoft()->clientSecret ) ) {
				throw new controllerException( 'Microsoft client secret has not been defined in the app config file. /app/config/environment.json > microsoft.clientSecret', 400 );
			}
			if( empty( config::getMicrosoft()->tenant ) ) {
				throw new controllerException( 'Microsoft tenant has not been defined in the app config file. /app/config/environment.json > microsoft.tenant', 400 );
			}

		}

		if( empty( $provider ) ) {
			throw new controllerException( 'We do not currently support logging in with the service you provided', 400 );
		}

		$authConfig = config::getServices()->auth;

		$config = [
			//Location where to redirect users once they authenticate with a provider
			'callback'  => config::getBaseUrl() . '/auth/hybridauth/' . $provider,

			//Providers specifics
			'providers' => [
				'Google'         => [
					'enabled'                  => false,
					'keys'                     => [
						'id'     => '',
						'secret' => ''
					],
					'authorize_url_parameters' => $authConfig->oauth->authorizeUrlParameters
				],
				'Facebook'       => [
					'enabled'                  => false,
					'keys'                     => [
						'id'     => '',
						'secret' => ''
					],
					'authorize_url_parameters' => $authConfig->oauth->authorizeUrlParameters
				],
				'MicrosoftGraph' => [
					'enabled'                  => true,
					'keys'                     => [
						'id'     => config::getMicrosoft()->clientId,
						'secret' => config::getMicrosoft()->clientSecret
					],
					'tenant'                   => config::getMicrosoft()->tenant,
					'scope'                    => 'openid offline_access profile email User.Read',
					'authorize_url_parameters' => $authConfig->oauth->authorizeUrlParameters
				],
			]
		];

		try {
			//Feed configuration array to Hybridauth
			$hybridauth = new \Hybridauth\Hybridauth( $config );

			//Then we can proceed and sign in with Twitter as an example. If you want to use a diffirent provider,
			//simply replace 'Twitter' with 'Google' or 'Facebook'.

			//Attempt to authenticate users with a provider by name
			$adapter = $hybridauth->authenticate( $provider );

			//Returns a boolean of whether the user is connected with Twitter
			$isConnected = $adapter->isConnected();

			//Retrieve the user's profile
			$oauthProfile = $adapter->getUserProfile();

			//Disconnect the adapter
			$adapter->disconnect();
		}
		catch( \Exception $e ) {
			error_log( $e );
			$message = $e->getMessage();
			switch( $e->getCode() ) {
				case 0 :
					$message = "Unspecified error.";
					break;
				case 1 :
					$message = "Hybridauth configuration error.";
					break;
				case 2 :
					$message = "Provider not properly configured.";
					break;
				case 3 :
					$message = "Unknown or disabled provider.";
					break;
				case 4 :
					$message = "Missing provider application credentials.";
					break;
				case 5 :
					$message = "The user has canceled the authentication or the provider refused the connection.";
					break;
				case 6 :
					$message = "User profile request failed. Most likely the user is not connected to the provider and he should authenticate again.";
					break;
				case 7 :
					$message = "User not connected to the provider.";
					break;
				case 8 :
					$message = "Provider does not support this feature.";
					break;
			}

			header( 'Location: ' . config::getJwtAuth()->redirectAfterLoginUrl . '?errorMessage=' . urlencode( $message ) );
			exit;
		}

		if( empty( $oauthProfile->email ) ) {
			throw new controllerException( $provider . ' did not provide us with your email address. That is a requirement to sign in.', 401 );
		}

		try {
			$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
			/** @var \gcgov\framework\interfaces\auth\user $user */
			$user = $userClassName::getFromOauth(
				email:            $oauthProfile->email,
				externalId:       $oauthProfile->identifier,
				externalProvider: $provider,
				firstName:        $oauthProfile->firstName,
				lastName:         $oauthProfile->lastName,
				addIfNotExisting: !$authConfig->blockNewUsers,
				rolesForNewUser:  $authConfig->defaultNewUserRoles );
		}
		catch( modelException $e ) {
			header( 'Location: ' . config::getJwtAuth()->redirectAfterLoginUrl . '?errorMessage=' . urlencode( $e->getMessage() ) );
			exit;
		}

		try {
			$userIdRaw = $user->getId();
			$userIdObject = $userIdRaw instanceof \MongoDB\BSON\ObjectId ? $userIdRaw : new \MongoDB\BSON\ObjectId( (string) $userIdRaw );
			$authorizationCode = new \gcgov\framework\services\jwtAuth\models\userAuthorizationCode( $userIdObject, new \DateInterval( 'PT5M' ) );
			\gcgov\framework\services\jwtAuth\models\userAuthorizationCode::save( $authorizationCode );
		}
		catch( modelException $e ) {
			throw new controllerException( $provider . 'Server failed to generate an access code.', 500, $e );
		}

		$appendState = '';
		if( !empty( $_SESSION[ 'auth_state' ] ) ) {
			$appendState = '&state=' . $_SESSION[ 'auth_state' ];
		}

		if( session_status()==PHP_SESSION_ACTIVE ) {
			session_destroy();
		}

		header( 'Location: ' . config::getJwtAuth()->redirectAfterLoginUrl . '?code=' . urlencode( (string)$authorizationCode->_id ) . $appendState );
		exit;
	}


	public function createExternalAppToken( string $appName, \DateInterval $tokenExpiration, \MongoDB\BSON\ObjectId $_id, string $username, string $email, string $name, array $roles = [], string $password = '' ): controllerDataResponse {
		$userClassName = \gcgov\framework\services\request::getUserClassFqdn();

		if( $password=='' ) {
			$password = uniqid();
		}

		$user           = new $userClassName();
		$user->_id      = $_id;
		$user->username = $username;
		$user->email    = $email;
		$user->name     = $name;
		$user->password = $password;
		$user->roles    = $roles;
		$user::save( $user );

		$authUser = \gcgov\framework\services\request::getAuthUser();
		$authUser->setFromUser( $user );

		$jwt   = new \gcgov\framework\services\jwtAuth\jwtAuth();
		$token = $jwt->createAccessToken( $authUser, $tokenExpiration );

		if( !file_exists( config::getRootDir() . '/externalAppTokens/' ) ) {
			$created = mkdir( config::getRootDir() . '/externalAppTokens/', 777, true );
			if( !$created ) {
				log::warning( 'auth', 'Directory "' . config::getRootDir() . '/externalAppTokens/" does not exist and could not be created automatically. Create directory to continue.' );
				throw new controllerException( 'Cannot create token because externalAppTokens directory does not exist' );
			}
		}

		$tokenFilePath = config::getRootDir() . '/externalAppTokens/' . formatting::fileName( $appName ) . '.txt';

		file_put_contents( $tokenFilePath, $token->toString() );

		return new controllerDataResponse( $tokenFilePath );
	}

	private function createAccessTokenResponse( \gcgov\framework\interfaces\auth\user $user ): stdAuthResponse {
		//create token for valid user
		$authUser = \gcgov\framework\services\request::getAuthUser();
		$authUser->setFromUser( $user );
		try {
			$jwtService  = new \gcgov\framework\services\jwtAuth\jwtAuth();
			$accessToken = $jwtService->createAccessToken( $authUser );

			try {
				$refreshToken = $jwtService->createRefreshToken( $authUser );
			}
			catch( modelException $e ) {
				throw new controllerException( 'Server failed to create refresh token', 500, $e );
			}

			return new stdAuthResponse( $accessToken, $refreshToken );
		}
		catch( \Exception $e ) {
			throw new controllerException( $e->getCode(), $e->getMessage(), $e );
		}
	}

	/**
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function verifyMfaSecret(): controllerDataResponse {
		$verifyMfaSecretRequestJSON = file_get_contents( 'php://input' );
		try {
			$verifyMfaSecretRequest = verifyMfaSecretRequest::jsonDeserialize( $verifyMfaSecretRequestJSON );
		}
		catch( jsonDeserializeException $e ) {
			throw new controllerException( 'Provided data is not in a valid format', 400, $e );
		}

		$authUser = \gcgov\framework\services\request::getAuthUser();

		$response = multifactor::verifyMfaSecret( new \MongoDB\BSON\ObjectId( $authUser->userId ), $verifyMfaSecretRequest->userMultifactorId, $verifyMfaSecretRequest->code );

		$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
		return new controllerDataResponse( $this->createAccessTokenResponse( $userClassName::getOne($authUser->userId) ) );
	}

	/**
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function verifyMfaCode(): controllerDataResponse {
		$verifyMfaCodeRequestJSON = file_get_contents( 'php://input' );
		try {
			$verifyMfaCodeRequest = verifyMfaCodeRequest::jsonDeserialize( $verifyMfaCodeRequestJSON );
		}
		catch( jsonDeserializeException $e ) {
			throw new controllerException( 'Provided data is not in a valid format', 400, $e );
		}

		$authUser = \gcgov\framework\services\request::getAuthUser();

		$valid = multifactor::isMfaCodeCorrect( new \MongoDB\BSON\ObjectId( $authUser->userId ), $verifyMfaCodeRequest->code );
		if(!$valid) {
			throw new controllerException('Invalid code', 500);
		}

		$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
		return new controllerDataResponse( $this->createAccessTokenResponse( $userClassName::getOne($authUser->userId) ) );
	}

}
