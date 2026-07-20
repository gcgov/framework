<?php

namespace gcgov\framework\services\microsoft;

use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;

/**
 * Builds signed JWT client assertions for Microsoft identity platform certificate credential
 * authentication (RFC 7523). Use in place of a client secret anywhere a token request would
 * post client_secret: send client_assertion_type=self::CLIENT_ASSERTION_TYPE and
 * client_assertion=clientAssertion::create() instead.
 *
 * See readme/microsoft-certificate-auth.md
 */
class clientAssertion {

	public const CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';


	/**
	 * Build a client assertion from the environment.json microsoft configuration
	 *
	 * @param string|null $audience Token endpoint the assertion will be posted to; defaults to the
	 *                              tenant's v2.0 token endpoint
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function create( ?string $audience = null ): string {
		$microsoftConfig = config::getEnvironmentConfig()->microsoft;

		if( !$microsoftConfig->useCertificateAuthentication() ) {
			throw new serviceException( 'Microsoft certificate authentication is not configured - set microsoft.certificatePath and microsoft.privateKeyPath in environment.json', 500 );
		}

		try {
			$certificatePem = $microsoftConfig->getCertificateContents();
			$privateKeyPem  = $microsoftConfig->getPrivateKeyContents();
		}
		catch( \gcgov\framework\exceptions\configException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}

		return self::createFromParts( $microsoftConfig->clientId, $microsoftConfig->tenant, $certificatePem, $privateKeyPem, $microsoftConfig->privateKeyPassphrase, $audience );
	}


	/**
	 * Build a client assertion from explicit credential parts
	 *
	 * @param string      $clientId             Entra ID application (client) id
	 * @param string      $tenant               Entra ID tenant id or domain; used for the default audience
	 * @param string      $certificatePem       PEM encoded public certificate uploaded to the app registration
	 * @param string      $privateKeyPem        PEM encoded private key for the certificate
	 * @param string      $privateKeyPassphrase Passphrase for the private key; empty for an unencrypted key
	 * @param string|null $audience             Token endpoint the assertion will be posted to; defaults to the
	 *                                          tenant's v2.0 token endpoint
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function createFromParts( string $clientId, string $tenant, string $certificatePem, string $privateKeyPem, string $privateKeyPassphrase = '', ?string $audience = null ): string {
		if( $audience===null || $audience==='' ) {
			$audience = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
		}

		//verify the private key matches the certificate so misconfiguration fails here with a clear
		//message instead of as an AADSTS error from the token endpoint
		$privateKey = openssl_pkey_get_private( $privateKeyPem, $privateKeyPassphrase );
		if( $privateKey===false ) {
			throw new serviceException( 'Unable to load Microsoft certificate private key - verify the key file and passphrase', 500 );
		}
		if( !openssl_x509_check_private_key( $certificatePem, $privateKey ) ) {
			throw new serviceException( 'Microsoft certificate private key does not match the certificate', 500 );
		}

		$now = new \DateTimeImmutable();

		try {
			//withUnixTimestampDates: Microsoft requires integer NumericDate claims (the default
			//formatter emits microsecond precision floats)
			$builder = new \Lcobucci\JWT\Token\Builder( new \Lcobucci\JWT\Encoding\JoseEncoder(), \Lcobucci\JWT\Encoding\ChainedFormatter::withUnixTimestampDates() );

			$token = $builder
				// Identifies the certificate to Entra ID: base64url encoded SHA-1 certificate hash
				->withHeader( 'x5t', self::getCertificateX5t( $certificatePem ) )
				// Configures the issuer (iss claim) - must be the client id
				->issuedBy( $clientId )
				// Configures the subject (sub claim) - must be the client id
				->relatedTo( $clientId )
				// Configures the audience (aud claim) - the token endpoint receiving the assertion
				->permittedFor( $audience )
				// Configures the id (jti claim) - unique per assertion to prevent replay
				->identifiedBy( \gcgov\framework\services\guid::create() )
				// Configures the time that the token was issued (iat claim)
				->issuedAt( $now )
				// Configures the time that the token can be used (nbf claim)
				->canOnlyBeUsedAfter( $now )
				// Configures the expiration time of the token (exp claim) - Microsoft recommends 10 minutes or less
				->expiresAt( $now->add( new \DateInterval( 'PT10M' ) ) )
				->getToken( new \Lcobucci\JWT\Signer\Rsa\Sha256(), \Lcobucci\JWT\Signer\Key\InMemory::plainText( $privateKeyPem, $privateKeyPassphrase ) );
		}
		catch( \Exception $e ) {
			throw new serviceException( 'Unable to sign Microsoft client assertion: ' . $e->getMessage(), 500, $e );
		}

		return $token->toString();
	}


	/**
	 * SHA-1 certificate thumbprint as uppercase hex - the format shown in the Entra ID portal and
	 * expected by \TheNetworg\OAuth2\Client\Provider\Azure clientCertificateThumbprint
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function getCertificateThumbprint( string $certificatePem ): string {
		$fingerprint = openssl_x509_fingerprint( $certificatePem, 'sha1' );
		if( $fingerprint===false ) {
			throw new serviceException( 'Unable to compute Microsoft certificate thumbprint - verify the certificate file is PEM encoded', 500 );
		}

		return strtoupper( $fingerprint );
	}


	/**
	 * Base64url encoded SHA-1 certificate hash for the JWT x5t header
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function getCertificateX5t( string $certificatePem ): string {
		$rawFingerprint = openssl_x509_fingerprint( $certificatePem, 'sha1', true );
		if( $rawFingerprint===false ) {
			throw new serviceException( 'Unable to compute Microsoft certificate thumbprint - verify the certificate file is PEM encoded', 500 );
		}

		return rtrim( strtr( base64_encode( $rawFingerprint ), '+/', '-_' ), '=' );
	}


	/**
	 * PEM private key contents with any passphrase encryption removed - for handing to libraries
	 * that cannot accept a passphrase (e.g. \TheNetworg\OAuth2\Client\Provider\Azure
	 * clientCertificatePrivateKey)
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function getDecryptedPrivateKeyPem( string $privateKeyPem, string $privateKeyPassphrase = '' ): string {
		if( $privateKeyPassphrase==='' ) {
			return $privateKeyPem;
		}

		$privateKey = openssl_pkey_get_private( $privateKeyPem, $privateKeyPassphrase );
		if( $privateKey===false ) {
			throw new serviceException( 'Unable to load Microsoft certificate private key - verify the key file and passphrase', 500 );
		}

		$decryptedPem = '';
		if( !openssl_pkey_export( $privateKey, $decryptedPem ) ) {
			throw new serviceException( 'Unable to export Microsoft certificate private key', 500 );
		}

		return $decryptedPem;
	}

}
