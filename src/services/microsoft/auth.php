<?php

namespace gcgov\framework\services\microsoft;


use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated('Use \andrewsauder\microsoftServices instead')]
class auth {

	private \TheNetworg\OAuth2\Client\Provider\Azure $provider;


	public function __construct() {
		$this->provider = new \TheNetworg\OAuth2\Client\Provider\Azure( (array) config::getEnvironmentConfig()->microsoft );
	}


	/**
	 * @param  string  $suppliedToken
	 *
	 * @return \League\OAuth2\Client\Token\AccessTokenInterface|\League\OAuth2\Client\Token\AccessToken
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function getAccessToken( string $suppliedToken ) : \League\OAuth2\Client\Token\AccessTokenInterface|\League\OAuth2\Client\Token\AccessToken {
		$this->provider->defaultEndPointVersion = \TheNetworg\OAuth2\Client\Provider\Azure::ENDPOINT_VERSION_2_0;
		$this->provider->scope                  = 'openid profile email offline_access';

		try {
			/** @var \TheNetworg\OAuth2\Client\Token\AccessToken $token */
			return $this->provider->getAccessToken( 'jwt_bearer', [
				'scope'               => $this->provider->scope,
				'assertion'           => $suppliedToken,
				'requested_token_use' => 'on_behalf_of',
			] );
		}
		catch( \Exception $e ) {
			throw new \gcgov\framework\exceptions\serviceException( 'Microsoft authentication failed', 400, $e );
		}
	}


	/**
	 * @return \gcgov\framework\services\microsoft\components\tokenInfomation
	 */
	public function verify() : \gcgov\framework\services\microsoft\components\tokenInfomation {
		$suppliedToken = str_replace( 'Bearer ', '', $_SERVER[ 'HTTP_AUTHORIZATION' ] );
		$token         = $this->getAccessToken( $suppliedToken );

		$claims  = $token->getIdTokenClaims();
		$expires = $token->getExpires();

		$tokenInformation                     = new components\tokenInfomation();
		$tokenInformation->iss                = $claims[ 'iss' ] ?? '';
		$tokenInformation->aud                = $claims[ 'aud' ] ?? '';
		$tokenInformation->oid                = $claims[ 'oid' ] ?? '';
		$tokenInformation->sub                = $claims[ 'sub' ] ?? '';
		$tokenInformation->appId              = $claims[ 'appid' ] ?? '';
		$tokenInformation->name               = $claims[ 'name' ] ?? '';
		$tokenInformation->familyName         = $claims[ 'family_name' ] ?? '';
		$tokenInformation->givenName          = $claims[ 'given_name' ] ?? '';
		$tokenInformation->ip                 = $claims[ 'ipaddr' ] ?? '';
		$tokenInformation->scope              = $claims[ 'scp' ] ?? '';
		$tokenInformation->email              = $claims[ 'email' ] ?? '';
		$tokenInformation->preferred_username = $claims[ 'preferred_username' ] ?? '';
		$tokenInformation->uniqueName         = $claims[ 'unique_name' ] ?? '';
		$tokenInformation->upn                = $claims[ 'upn' ] ?? '';

		return $tokenInformation;
	}


	/**
	 * @return string
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function getApplicationAccessToken() : string {
		//get application access token
		try {
			$guzzle = new \GuzzleHttp\Client();
			$url    = 'https://login.microsoftonline.com/' . config::getEnvironmentConfig()->microsoft->tenant . '/oauth2/token?api-version=1.0';
			$token  = json_decode( $guzzle->post( $url, [
				'form_params' => [
					'client_id'     => config::getEnvironmentConfig()->microsoft->clientId,
					'client_secret' => config::getEnvironmentConfig()->microsoft->clientSecret,
					'resource'      => 'https://graph.microsoft.com/',
					'grant_type'    => 'client_credentials',
				],
			] )->getBody()->getContents() );

			return $token->access_token;
		}
		catch( GuzzleException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}
	}

}
