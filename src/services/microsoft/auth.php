<?php

namespace gcgov\framework\services\microsoft;


use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated('Use \andrewsauder\microsoftServices instead')]
class auth {

	private \TheNetworg\OAuth2\Client\Provider\Azure $provider;


	/**
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function __construct() {
		$microsoftConfig = config::getEnvironmentConfig()->microsoft;

		$options = (array) $microsoftConfig;

		//prefer certificate credential authentication when configured; the provider builds the
		//client assertion internally from the private key and thumbprint
		if( $microsoftConfig->useCertificateAuthentication() ) {
			try {
				unset( $options[ 'clientSecret' ] );
				$options[ 'clientCertificatePrivateKey' ] = clientAssertion::getDecryptedPrivateKeyPem( $microsoftConfig->getPrivateKeyContents(), $microsoftConfig->privateKeyPassphrase );
				$options[ 'clientCertificateThumbprint' ] = clientAssertion::getCertificateThumbprint( $microsoftConfig->getCertificateContents() );
			}
			catch( \gcgov\framework\exceptions\configException $e ) {
				throw new serviceException( $e->getMessage(), 500, $e );
			}
		}

		$this->provider = new \TheNetworg\OAuth2\Client\Provider\Azure( $options );
	}


	/**
	 * @param  string  $suppliedToken
	 *
	 * @return \TheNetworg\OAuth2\Client\Token\AccessToken
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function getAccessToken( string $suppliedToken ) : \TheNetworg\OAuth2\Client\Token\AccessToken {
		$this->provider->defaultEndPointVersion = \TheNetworg\OAuth2\Client\Provider\Azure::ENDPOINT_VERSION_2_0;
		$this->provider->scope                  = 'openid profile email offline_access';

		try {
			$token = $this->provider->getAccessToken( 'jwt_bearer', [
				'scope'               => $this->provider->scope,
				'assertion'           => $suppliedToken,
				'requested_token_use' => 'on_behalf_of',
			] );
		}
		catch( \Exception $e ) {
			throw new \gcgov\framework\exceptions\serviceException( 'Microsoft authentication failed', 400, $e );
		}

		if( !( $token instanceof \TheNetworg\OAuth2\Client\Token\AccessToken ) ) {
			throw new \gcgov\framework\exceptions\serviceException( 'Microsoft provider returned an unexpected token implementation', 500 );
		}

		return $token;
	}


	/**
	 * @return \gcgov\framework\services\microsoft\components\tokenInfomation
	 */
	public function verify() : \gcgov\framework\services\microsoft\components\tokenInfomation {
		$suppliedToken = str_replace( 'Bearer ', '', (string) ( $_SERVER[ 'HTTP_AUTHORIZATION' ] ?? '' ) );
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
		$microsoftConfig = config::getEnvironmentConfig()->microsoft;

		$tokenEndpoint = 'https://login.microsoftonline.com/' . $microsoftConfig->tenant . '/oauth2/token';

		$formParams = [
			'client_id'  => $microsoftConfig->clientId,
			'resource'   => 'https://graph.microsoft.com/',
			'grant_type' => 'client_credentials',
		];

		//prefer certificate credential authentication when configured
		if( $microsoftConfig->useCertificateAuthentication() ) {
			$formParams[ 'client_assertion_type' ] = clientAssertion::CLIENT_ASSERTION_TYPE;
			$formParams[ 'client_assertion' ]      = clientAssertion::create( $tokenEndpoint );
		}
		else {
			$formParams[ 'client_secret' ] = $microsoftConfig->clientSecret;
		}

		//get application access token
		try {
			$guzzle = new \GuzzleHttp\Client();
			$token  = json_decode( $guzzle->post( $tokenEndpoint . '?api-version=1.0', [
				'form_params' => $formParams,
			] )->getBody()->getContents() );

			return $token->access_token;
		}
		catch( GuzzleException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}
	}

}
